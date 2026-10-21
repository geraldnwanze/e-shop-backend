<?php

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Models\EmailSuppression;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\BackInStockMessage;
use App\Support\Notifications\StoreMailContext;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('stock:notify-back-in-stock')]
#[Description('Email shoppers who asked to be told when a sold-out product is available again')]
class NotifyBackInStock extends Command
{
    /** Requests older than this are dropped without an email. */
    private const MAX_AGE_DAYS = 180;

    public function handle(TenantContext $context): int
    {
        $sent = 0;

        StockAlert::withoutTenantScope()->where('created_at', '<', now()->subDays(self::MAX_AGE_DAYS))->whereNull('notified_at')->delete();

        Tenant::query()->whereIn('id', StockAlert::withoutTenantScope()->whereNull('notified_at')->select('tenant_id')->distinct())
            ->each(function (Tenant $tenant) use ($context, &$sent): void {
                $context->runAs($tenant, function () use ($tenant, &$sent): void {
                    $mail = StoreMailContext::current();

                    StockAlert::query()->whereNull('notified_at')->with(['product', 'variant'])->orderBy('created_at')
                        ->each(function (StockAlert $alert) use ($tenant, $mail, &$sent): void {
                            $sent += (int) $this->notify($tenant, $alert, $mail);
                        });
                });
            });

        $this->info("Sent {$sent} back-in-stock email(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     */
    private function notify(Tenant $tenant, StockAlert $alert, array $mail): bool
    {
        $product = $alert->product;
        $target = $alert->variant ?? $product;

        // Nothing to say yet, or the product was withdrawn: leave it waiting (or close it if gone for good).
        if ($product === null || ($alert->variant_id !== null && $alert->variant === null)) {
            $alert->delete();

            return false;
        }

        if ($product->status !== ProductStatus::Active || $target->stock <= 0 || ! $tenant->acceptsOrders()) {
            return false;
        }

        $alert->forceFill(['notified_at' => now()])->save();

        if (EmailSuppression::suppresses($alert->email)) {
            return false;
        }

        $name = $alert->variant ? "{$product->name} ({$alert->variant->name})" : $product->name;

        Notification::route('mail', $alert->email)->notify(new StoreMessageNotification(
            'back_in_stock',
            "{$name} is back in stock",
            BackInStockMessage::build($mail, $name, StorefrontUrl::for($tenant, "/products/{$product->slug}"), UnsubscribeLink::url($tenant->getKey(), $alert->email)),
        ));

        return true;
    }
}
