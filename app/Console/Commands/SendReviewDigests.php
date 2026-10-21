<?php

namespace App\Console\Commands;

use App\Models\ProductReview;
use App\Models\Store;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\ReviewDigestMessage;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('reviews:send-digests')]
#[Description('Email each store that asked for it one summary of the reviews it received since the last one')]
class SendReviewDigests extends Command
{
    /** Local time of day the summary goes out. */
    private const SEND_HOUR = 8;

    /** After a long gap, only summarise this far back. */
    private const MAX_LOOKBACK_DAYS = 7;

    public function handle(TenantContext $context): int
    {
        $sent = 0;

        Tenant::query()->whereIn('id', ProductReview::withoutTenantScope()->where('created_at', '>=', now()->subDays(self::MAX_LOOKBACK_DAYS))->select('tenant_id')->distinct())
            ->each(function (Tenant $tenant) use ($context, &$sent): void {
                if (! $tenant->acceptsOrders()) {
                    return;
                }

                $context->runAs($tenant, function () use (&$sent): void {
                    $preferences = NotificationPreferences::forCurrentTenant();
                    $store = Store::query()->first();

                    if ($store === null || ! $preferences->enabled('merchant_new_review') || ! $preferences->enabled('merchant_review_digest') || ! $this->due($store)) {
                        return;
                    }

                    $now = now();
                    $since = $store->review_digest_checked_at ?? $now->copy()->subDay();
                    $since = $since->lessThan($now->copy()->subDays(self::MAX_LOOKBACK_DAYS)) ? $now->copy()->subDays(self::MAX_LOOKBACK_DAYS) : $since;

                    $reviews = ProductReview::query()->with('product')->where('created_at', '>', $since)->where('created_at', '<=', $now)->get();

                    // Move the marker even on a quiet day, so tomorrow's summary starts from now.
                    $store->forceFill(['review_digest_checked_at' => $now])->saveQuietly();

                    if ($reviews->isEmpty() || ! ($to = $preferences->merchantRecipient())) {
                        return;
                    }

                    $message = ReviewDigestMessage::build(StoreMailContext::current(), $reviews);
                    Notification::route('mail', $to)->notify(new StoreMessageNotification('review_digest', $message['subject'], $message['content']));
                    $sent++;
                });
            });

        $this->info("Sent {$sent} review summar".($sent === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    /**
     * It is past the send hour in the store's own time zone, and today's summary has not gone out yet.
     */
    private function due(Store $store): bool
    {
        $local = now()->setTimezone($store->timezone ?: 'UTC');
        $todayAtSendHour = $local->copy()->setTime(self::SEND_HOUR, 0);

        return $local->greaterThanOrEqualTo($todayAtSendHour)
            && ($store->review_digest_checked_at === null || $store->review_digest_checked_at->lessThan($todayAtSendHour));
    }
}
