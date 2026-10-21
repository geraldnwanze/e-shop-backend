<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Services\Reviews\ReviewService;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\ReviewRequestMessage;
use App\Support\Notifications\StoreMailContext;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('reviews:send-requests')]
#[Description('Ask customers to review what they bought a few days after delivery, for stores that switched it on')]
class SendReviewRequests extends Command
{
    /** Past this, the order is too old for a request to feel natural. */
    private const MAX_AGE_DAYS = 60;

    /** Most products listed in one email. */
    private const MAX_PRODUCTS = 5;

    /** One request per customer in this window, so several orders don't mean several emails. */
    private const CUSTOMER_COOLDOWN_DAYS = 7;

    public function handle(TenantContext $context, ReviewService $reviews): int
    {
        $sent = 0;

        Tenant::query()->whereIn('id', Order::withoutTenantScope()->whereNull('review_requested_at')->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', now()->subDays(self::MAX_AGE_DAYS))->select('tenant_id')->distinct())
            ->each(function (Tenant $tenant) use ($context, $reviews, &$sent): void {
                if (! $tenant->acceptsOrders()) {
                    return;
                }

                $context->runAs($tenant, function () use ($tenant, $reviews, &$sent): void {
                    $preferences = NotificationPreferences::forCurrentTenant();

                    if (! $preferences->enabled('review_request') || ! $reviews->settings()['enabled']) {
                        return;
                    }

                    $mail = StoreMailContext::current();

                    Order::query()->with('items')
                        ->where('channel', 'online')
                        ->whereNull('review_requested_at')
                        ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
                        ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                        ->where('delivered_at', '<=', now()->subDays($preferences->reviewRequestDelayDays()))
                        ->where('delivered_at', '>=', now()->subDays(self::MAX_AGE_DAYS))
                        ->orderBy('delivered_at')
                        ->each(function (Order $order) use ($tenant, $mail, &$sent): void {
                            $sent += (int) $this->request($tenant, $order, $mail);
                        });
                });
            });

        $this->info("Sent {$sent} review request(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array{store: string, accent: string, supportEmail: string|null}  $mail
     */
    private function request(Tenant $tenant, Order $order, array $mail): bool
    {
        $email = mb_strtolower($order->customer_email);

        // Decided without sending: never ask a person who left, and never ask the same person twice in a week.
        if ($email === '' || EmailSuppression::suppresses($email)) {
            $order->forceFill(['review_requested_at' => now()])->saveQuietly();

            return false;
        }

        if (Order::query()->where('customer_email', $email)->where('review_requested_at', '>=', now()->subDays(self::CUSTOMER_COOLDOWN_DAYS))->whereNotNull('review_requested_at')->exists()) {
            return false;
        }

        $reviewed = ProductReview::query()->where('customer_id', $order->customer_id)->pluck('product_id')->all();
        $productIds = $order->items->pluck('product_id')->filter()->unique()->reject(fn ($id) => in_array($id, $reviewed, true))->all();
        $products = Product::query()->whereIn('id', $productIds)->where('status', ProductStatus::Active->value)->limit(self::MAX_PRODUCTS)->get();

        // Nothing left to review (or nothing still on sale): close the order so it is not examined again.
        if ($products->isEmpty()) {
            $order->forceFill(['review_requested_at' => now()])->saveQuietly();

            return false;
        }

        $order->forceFill(['review_requested_at' => now()])->saveQuietly();

        $message = ReviewRequestMessage::build(
            $mail,
            explode(' ', trim($order->customer_name))[0] ?? null,
            $order->order_number,
            $products->map(fn (Product $p) => [$p->name, StorefrontUrl::for($tenant, "/products/{$p->slug}#reviews")])->all(),
            UnsubscribeLink::url($tenant->getKey(), $email),
        );

        Notification::route('mail', $email)->notify(new StoreMessageNotification('review_request', 'How was your order?', $message));

        return true;
    }
}
