<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ModerateReviewRequest;
use App\Http\Resources\ProductReviewResource;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Services\Reviews\ReviewPhotoService;
use App\Services\Reviews\ReviewService;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Merchant: Product reviews', weight: 22)]
class ProductReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductReview::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([ProductReview::STATUS_PUBLISHED, ProductReview::STATUS_PENDING, ProductReview::STATUS_HIDDEN])],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'product_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'unanswered' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $reviews = ProductReview::query()->with(['product', 'customer', 'photos.media'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($request->boolean('unanswered'), fn ($q) => $q->whereNull('reply'))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('title', Like::contains($term))
                ->orWhereLike('body', Like::contains($term))
                ->orWhereLike('author_name', Like::contains($term))))
            ->latest('created_at')->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(ProductReviewResource::collection($reviews), meta: ['pending' => ProductReview::query()->where('status', ProductReview::STATUS_PENDING)->count()]);
    }

    public function update(ModerateReviewRequest $request, ProductReview $review): JsonResponse
    {
        $this->authorize('update', $review);

        return ApiResponse::success(ProductReviewResource::make($this->reviews->moderate($review, $request->validated())->load(['product', 'customer', 'photos.media'])), 'Review updated.');
    }

    /**
     * Take one photo off a review (for example an inappropriate one) without hiding the review itself.
     */
    public function destroyPhoto(ProductReview $review, ReviewPhoto $photo, ReviewPhotoService $photos): JsonResponse
    {
        $this->authorize('update', $review);
        abort_unless($photo->product_review_id === $review->getKey(), 404);

        $photos->delete($photo);

        return ApiResponse::success(ProductReviewResource::make($review->load(['product', 'customer', 'photos.media'])), 'Photo removed.');
    }

    public function destroy(ProductReview $review): JsonResponse
    {
        $this->authorize('delete', $review);

        $this->reviews->delete($review);

        return ApiResponse::noContent('Review deleted.');
    }
}
