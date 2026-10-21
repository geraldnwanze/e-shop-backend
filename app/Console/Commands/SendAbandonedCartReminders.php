<?php

namespace App\Console\Commands;

use App\Enums\DiscountType;
use App\Models\Cart;
use App\Models\Discount;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\AbandonedCartMessage;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

#[Signature('carts:send-abandoned-reminders')]
#[Description('Email shoppers who left items in their cart: a first reminder, then an optional follow-up with a discount code')]
class SendAbandonedCartReminders extends Command
{
    /** A first reminder is not worth sending for carts older than this. */
    private const MAX_AGE_DAYS = 7;

    /** Follow-ups can wait longer, since they count from the first reminder. */
    private const FOLLOW_UP_MAX_AGE_DAYS = 14;

    public function handle(TenantContext $context): int
    {
        $sent = ['first' => 0, 'second' => 0];

        Tenant::query()->whereIn('id', Cart::withoutTenantScope()
            ->whereNull('converted_at')->whereNull('second_reminder_sent_at')
            ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('customer_id'))
            ->where('updated_at', '>=', now()->subDays(self::FOLLOW_UP_MAX_AGE_DAYS))
            ->select('tenant_id')->distinct())->each(function (Tenant $tenant) use ($context, &$sent): void {
                if (! $tenant->acceptsOrders()) {
                    return;
                }

                $context->runAs($tenant, function () use ($tenant, &$sent): void {
                    $preferences = NotificationPreferences::forCurrentTenant();

                    if (! $preferences->enabled('abandoned_cart_reminder')) {
                        return;
                    }

                    $mail = StoreMailContext::current();

                    $this->eligible()
                        ->whereNull('reminder_sent_at')
                        ->where('updated_at', '<=', now()->subHours($preferences->abandonedCartDelayHours()))
                        ->where('updated_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
                        ->each(function (Cart $cart) use ($tenant, $mail, &$sent): void {
                            $sent['first'] += (int) $this->remind($tenant, $cart, $mail, null);
                        });

                    if (! $preferences->enabled('abandoned_cart_second_reminder')) {
                        return;
                    }

                    $percent = $preferences->recoveryDiscountPercent();

                    $this->eligible()
                        ->whereNotNull('reminder_sent_at')->whereNull('second_reminder_sent_at')
                        ->where('updated_at', '<=', now()->subHours($preferences->secondReminderDelayHours()))
                        ->where('updated_at', '>=', now()->subDays(self::FOLLOW_UP_MAX_AGE_DAYS))
                        ->each(function (Cart $cart) use ($tenant, $mail, $percent, &$sent): void {
                            $sent['second'] += (int) $this->remind($tenant, $cart, $mail, $percent);
                        });
                });
            });

        $this->info("Sent {$sent['first']} abandoned-cart reminder(s) and {$sent['second']} follow-up(s).");

        return self::SUCCESS;
    }

    /**
     * Open carts we know the owner of and that still hold items.
     *
     * @return Builder<Cart>
     */
    private function eligible(): Builder
    {
        return Cart::query()
            ->whereNull('converted_at')
            ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('customer_id'))
            ->whereHas('items')
            ->with(['customer', 'items.product', 'items.variant']);
    }

    /**
     * Send the first reminder ($followUpPercent null) or the follow-up (percent 0 = no code).
     *
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     */
    private function remind(Tenant $tenant, Cart $cart, array $mail, ?int $followUpPercent): bool
    {
        $email = $cart->customer?->email ?? $cart->email;

        if ($email === null || EmailSuppression::suppresses($email)) {
            return false;
        }

        // They bought something since: nothing to chase.
        if (Order::query()->where('customer_email', $email)->where('created_at', '>=', $cart->created_at)->exists()) {
            return false;
        }

        $items = $cart->items->filter(fn ($item) => $item->isAvailable());

        if ($items->isEmpty()) {
            return false;
        }

        $isFollowUp = $followUpPercent !== null;
        $code = $isFollowUp && $followUpPercent > 0 ? $this->codeFor($cart, $followUpPercent) : null;

        $cart->forceFill($isFollowUp
            ? ['second_reminder_sent_at' => now(), 'last_reminder_at' => now(), 'last_reminder_stage' => 'second']
            : ['reminder_sent_at' => now(), 'last_reminder_at' => now(), 'last_reminder_stage' => 'first'])->saveQuietly();

        $lines = $items->map(fn ($i) => ["{$i->quantity} × {$i->product->name}".($i->variant ? " ({$i->variant->name})" : ''), StoreMailContext::money($i->lineTotal(), $cart->currency)])->values()->all();
        $path = "/cart/recover/{$cart->getKey()}".($code ? '?code='.urlencode($code->code) : '');

        $message = AbandonedCartMessage::build(
            $mail,
            $isFollowUp,
            $code ? ['code' => $code->code, 'value' => $code->value, 'ends_at' => $code->ends_at] : null,
            $lines,
            StoreMailContext::money($items->sum(fn ($i) => $i->lineTotal()), $cart->currency),
            StorefrontUrl::for($tenant, $path),
            UnsubscribeLink::url($tenant->getKey(), $email),
        );

        Notification::route('mail', $email)->notify(new StoreMessageNotification('abandoned_cart', $message['subject'], $message['content']));

        return true;
    }

    /**
     * A single-use code made for this cart, so it cannot be passed around. Re-uses the code if the
     * cart already has one (for example after a failed send).
     */
    private function codeFor(Cart $cart, int $percent): Discount
    {
        if ($cart->recovery_code !== null && ($existing = Discount::query()->where('code', $cart->recovery_code)->first()) !== null) {
            return $existing;
        }

        do {
            $code = 'BACK-'.strtoupper(Str::random(6));
        } while (Discount::withTrashed()->where('code', $code)->exists());

        $discount = new Discount([
            'code' => $code,
            'type' => DiscountType::Percentage,
            'value' => $percent,
            'min_subtotal' => 0,
            'max_uses' => 1,
            'ends_at' => now()->addDays(AbandonedCartMessage::CODE_VALID_DAYS),
            'is_active' => true,
        ]);
        $discount->source = Discount::SOURCE_CART_RECOVERY;
        $discount->save();

        $cart->forceFill(['recovery_code' => $code])->saveQuietly();

        return $discount;
    }
}
