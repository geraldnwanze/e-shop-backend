<?php

namespace App\Listeners;

use App\Events\ProductReviewSubmitted;
use App\Models\ProductReview;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the merchant a customer reviewed a product, or that a review is waiting for their approval.
 */
class SendReviewAlert implements ShouldQueueAfterCommit
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ProductReviewSubmitted $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($event): void {
            $preferences = NotificationPreferences::forCurrentTenant();
            $review = ProductReview::query()->with(['product', 'customer'])->find($event->reviewId);

            if ($review === null || $review->product === null || ! $preferences->enabled('merchant_new_review') || $preferences->enabled('merchant_review_digest') || ! ($to = $preferences->merchantRecipient())) {
                return;
            }

            $mail = StoreMailContext::current();
            $waiting = $review->status === ProductReview::STATUS_PENDING;
            $stars = str_repeat('★', $review->rating).str_repeat('☆', 5 - $review->rating);
            $product = $review->product->name;
            $lead = $event->isNew ? "{$review->author_name} reviewed {$product}" : "{$review->author_name} edited their review of {$product}";

            $subject = $waiting ? "Review waiting for approval: {$product}" : "New {$review->rating}-star review: {$product}";

            Notification::route('mail', $to)->notify(new StoreMessageNotification('merchant_review', $subject, [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "{$stars} {$lead}.",
                'heading' => $waiting ? 'A review is waiting for you' : 'You have a new review',
                'intro' => $waiting
                    ? "{$lead}. It will not appear on your store until you approve it."
                    : "{$lead}. It is already live on your product page.".($review->rating <= 2 ? ' It is a low rating, so you may want to reply.' : ''),
                'details' => array_values(array_filter([
                    ['Product', $product],
                    ['Rating', "{$stars}  ({$review->rating}/5)"],
                    ['Customer', trim($review->author_name.($review->customer?->email ? " · {$review->customer->email}" : ''))],
                    $review->title ? ['Headline', $review->title] : null,
                    $review->photos()->count() > 0 ? ['Photos', (string) $review->photos()->count()] : null,
                ])),
                'body' => $review->body ? [$review->body] : [],
                'button' => ['label' => $waiting ? 'Approve or hide it' : 'Read and reply', 'url' => "{$mail['dashboardUrl']}/reviews".($waiting ? '?status=pending' : '')],
                'outro' => 'You get this when a customer reviews a product. Turn it off under Settings & payments.',
            ]));
        });
    }
}
