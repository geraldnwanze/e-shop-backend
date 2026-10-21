<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\SubmitReviewRequest;
use App\Http\Requests\Reviews\UploadReviewPhotoRequest;
use App\Http\Resources\StorefrontReviewResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Services\Reviews\ReviewPhotoService;
use App\Services\Reviews\ReviewService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Storefront: Reviews', weight: 12)]
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Published reviews for a product, newest first, with the rating summary in `meta.summary`.
     */
    public function index(string $slug): JsonResponse
    {
        $product = $this->product($slug);

        $list = $this->reviews->settings()['enabled']
            ? ProductReview::query()->with('photos.media')->where('product_id', $product->getKey())->where('status', ProductReview::STATUS_PUBLISHED)->latest('created_at')->latest('id')->paginate(10)
            : ProductReview::query()->whereRaw('1 = 0')->paginate(10);

        return ApiResponse::success(StorefrontReviewResource::collection($list), meta: ['summary' => $this->reviews->summary($product), 'photos' => $this->reviews->settings()['enabled'] ? $this->reviews->recentPhotos($product) : []]);
    }

    /**
     * Whether the signed-in customer may review this product, and their existing review if any.
     */
    public function mine(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $customer = $this->customer($request);
        $existing = ProductReview::query()->with('photos.media')->where('product_id', $product->getKey())->where('customer_id', $customer->getKey())->first();

        return ApiResponse::success($this->reviews->eligibility($customer, $product) + ['review' => $existing ? StorefrontReviewResource::make($existing)->resolve() : null]);
    }

    public function store(SubmitReviewRequest $request, string $slug): JsonResponse
    {
        $review = $this->reviews->submit($this->customer($request), $this->product($slug), $request->validated())->load('photos.media');

        return ApiResponse::success(StorefrontReviewResource::make($review), $review->status === ProductReview::STATUS_PENDING ? 'Thanks! Your review will appear once the store approves it.' : 'Thanks for your review!');
    }

    /**
     * Upload one photo to attach to a review. It is re-encoded, so only a clean JPEG is kept.
     */
    public function uploadPhoto(UploadReviewPhotoRequest $request, ReviewPhotoService $photos): JsonResponse
    {
        $photo = $photos->upload($this->customer($request), $request->file('photo'));

        return ApiResponse::created(['id' => $photo->id, 'url' => $photo->media->url, 'width' => $photo->media->width, 'height' => $photo->media->height], 'Photo uploaded.');
    }

    /**
     * Remove a photo that was uploaded but never put on a review.
     */
    public function destroyPhoto(Request $request, string $photo, ReviewPhotoService $photos): JsonResponse
    {
        $found = ReviewPhoto::query()->where('customer_id', $this->customer($request)->getKey())->whereNull('product_review_id')->where('id', $photo)->firstOrFail();

        $photos->delete($found);

        return ApiResponse::noContent('Photo removed.');
    }

    public function destroyMine(Request $request, string $slug): JsonResponse
    {
        $product = $this->product($slug);
        $review = ProductReview::query()->where('product_id', $product->getKey())->where('customer_id', $this->customer($request)->getKey())->firstOrFail();

        $this->reviews->delete($review);

        return ApiResponse::noContent('Review deleted.');
    }

    private function product(string $slug): Product
    {
        return Product::query()->where('status', ProductStatus::Active)->where('slug', $slug)->firstOrFail();
    }

    private function customer(Request $request): Customer
    {
        $user = $request->user();
        abort_unless($user instanceof Customer, 403);

        return $user;
    }
}
