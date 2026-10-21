<?php

namespace App\Services\Reviews;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\ProductReviewSubmitted;
use App\Exceptions\BaseApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    /**
     * @return array{enabled: bool, require_approval: bool}
     */
    public function settings(): array
    {
        $stored = StoreSetting::query()->first()?->settings['reviews'] ?? [];
        $defaults = config('themes.default_settings.reviews');

        return ['enabled' => (bool) ($stored['enabled'] ?? $defaults['enabled']), 'require_approval' => (bool) ($stored['require_approval'] ?? $defaults['require_approval'])];
    }

    /**
     * The order that lets this customer review the product: paid, not cancelled, and containing it.
     */
    public function purchaseFor(Customer $customer, Product $product): ?Order
    {
        return Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->getKey()))
            ->latest('placed_at')->first();
    }

    /**
     * Whether the customer has bought anything (a paid, uncancelled order): the bar for adding photos.
     */
    public function hasPurchased(Customer $customer): bool
    {
        return Order::query()->where('customer_id', $customer->getKey())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->exists();
    }

    /**
     * @return array{eligible: bool, reason: ?string}
     */
    public function eligibility(Customer $customer, Product $product): array
    {
        if (! $this->settings()['enabled']) {
            return ['eligible' => false, 'reason' => 'reviews_off'];
        }

        return $this->purchaseFor($customer, $product) === null ? ['eligible' => false, 'reason' => 'purchase_required'] : ['eligible' => true, 'reason' => null];
    }

    /**
     * Create or update the customer's review of the product. With approval on, every new or edited
     * review waits for the merchant.
     *
     * @param  array{rating: int, title?: ?string, body?: ?string, photo_ids?: list<string>}  $data
     */
    public function submit(Customer $customer, Product $product, array $data): ProductReview
    {
        $settings = $this->settings();

        if (! $settings['enabled']) {
            throw BaseApiException::conflict('Reviews are switched off for this store.');
        }

        $order = $this->purchaseFor($customer, $product);

        if ($order === null) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'Only customers who bought this product can review it.');
        }

        $review = ProductReview::query()->firstOrNew(['product_id' => $product->getKey(), 'customer_id' => $customer->getKey()]);
        $isNew = ! $review->exists;
        $review->fill([
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'author_name' => $this->authorName($customer),
            'status' => $settings['require_approval'] ? ProductReview::STATUS_PENDING : ProductReview::STATUS_PUBLISHED,
        ]);
        $review->order_id = $order->getKey();

        DB::transaction(function () use ($review, $customer, $data): void {
            $review->save();

            if (array_key_exists('photo_ids', $data)) {
                app(ReviewPhotoService::class)->sync($review, $customer, $data['photo_ids'] ?? []);
            }
        });

        $this->recalculate($product);

        // The merchant hears about every new review, and about edits that put one back in the approval queue.
        if ($isNew || $review->status === ProductReview::STATUS_PENDING) {
            event(new ProductReviewSubmitted($review->tenant_id, $review->getKey(), $isNew));
        }

        return $review->refresh();
    }

    public function delete(ProductReview $review): void
    {
        $product = $review->product;
        $photos = app(ReviewPhotoService::class);
        $review->photos()->with('media')->get()->each(fn ($p) => $photos->delete($p));
        $review->delete();

        if ($product !== null) {
            $this->recalculate($product);
        }
    }

    /**
     * Merchant moderation: publish or hide a review, and answer it.
     *
     * @param  array{status?: string, reply?: ?string}  $changes
     */
    public function moderate(ProductReview $review, array $changes): ProductReview
    {
        if (array_key_exists('reply', $changes)) {
            $reply = trim((string) $changes['reply']);
            $review->reply = $reply === '' ? null : $reply;
            $review->replied_at = $reply === '' ? null : now();
        }

        if (isset($changes['status'])) {
            $review->status = $changes['status'];
        }

        $review->save();

        if ($review->product !== null) {
            $this->recalculate($review->product);
        }

        return $review;
    }

    /**
     * Keep the product's average and count in step with its published reviews.
     */
    public function recalculate(Product $product): void
    {
        $published = ProductReview::query()->where('product_id', $product->getKey())->where('status', ProductReview::STATUS_PUBLISHED);

        $count = (clone $published)->count();
        $average = $count > 0 ? round((float) (clone $published)->avg('rating'), 2) : 0;

        Product::query()->whereKey($product->getKey())->update(['rating_average' => $average, 'rating_count' => $count]);
    }

    /**
     * The latest photos from published reviews of this product, for the strip above the reviews.
     *
     * @return list<array{id: string, url: string, reviewId: string}>
     */
    public function recentPhotos(Product $product, int $limit = 8): array
    {
        return ReviewPhoto::query()->with('media')
            ->whereHas('review', fn ($q) => $q->where('product_id', $product->getKey())->where('status', ProductReview::STATUS_PUBLISHED))
            ->latest('created_at')->limit($limit)->get()
            ->filter(fn (ReviewPhoto $p) => $p->media !== null)
            ->map(fn (ReviewPhoto $p) => ['id' => $p->id, 'url' => $p->media->url, 'reviewId' => $p->product_review_id])->values()->all();
    }

    /**
     * @return array{average: float, count: int, distribution: array<int, int>}
     */
    public function summary(Product $product): array
    {
        $rows = ProductReview::query()->where('product_id', $product->getKey())->where('status', ProductReview::STATUS_PUBLISHED)
            ->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');

        $distribution = [];

        foreach (range(5, 1) as $stars) {
            $distribution[$stars] = (int) ($rows[$stars] ?? 0);
        }

        return ['average' => (float) $product->fresh()->rating_average, 'count' => array_sum($distribution), 'distribution' => $distribution];
    }

    /**
     * "Ada Obi" is shown as "Ada O." so reviews do not publish customers' full names.
     */
    private function authorName(Customer $customer): string
    {
        $last = trim($customer->last_name);

        return trim($customer->first_name.($last !== '' ? ' '.mb_substr($last, 0, 1).'.' : ''));
    }
}
