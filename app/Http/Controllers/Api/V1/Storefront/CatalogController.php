<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\StorefrontProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Storefront: Store & catalogue', weight: 10)]
class CatalogController extends Controller
{
    private const RELATED_LIMIT = 4;

    public function categories(): JsonResponse
    {
        return ApiResponse::success(CategoryResource::collection(
            Category::query()->where('is_active', true)
                ->withCount(['products' => fn ($q) => $q->where('status', ProductStatus::Active)])
                ->orderBy('position')->orderBy('name')->get(),
        ));
    }

    public function products(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'max:140'],
            'search' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $products = Product::query()
            ->where('status', ProductStatus::Active)
            ->with(['category', 'variants', 'images.media'])
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)->where('is_active', true)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereLike('name', Like::contains($term)))
            ->when(isset($filters['min_price']), fn ($q) => $q->where('price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn ($q) => $q->where('price', '<=', $filters['max_price']))
            ->when(
                $filters['sort'] ?? 'newest',
                fn ($q, $sort) => match ($sort) {
                    'price_asc' => $q->orderBy('price'),
                    'price_desc' => $q->orderByDesc('price'),
                    'name' => $q->orderBy('name'),
                    default => $q->latest('id'),
                },
            )
            ->paginate($filters['per_page'] ?? 12);

        return ApiResponse::success(StorefrontProductResource::collection($products));
    }

    public function product(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('status', ProductStatus::Active)
            ->with(['category', 'variants', 'images.media'])
            ->where('slug', $slug)
            ->firstOrFail();

        return ApiResponse::success(StorefrontProductResource::make($product));
    }

    /**
     * Up to four other products to suggest on a product page: same category first, then the newest
     * of the rest, with things that can actually be bought ahead of sold-out ones.
     */
    public function related(string $slug): JsonResponse
    {
        $product = Product::query()->where('status', ProductStatus::Active)->where('slug', $slug)->firstOrFail();

        $candidates = Product::query()
            ->where('status', ProductStatus::Active)
            ->whereKeyNot($product->getKey())
            ->with(['category', 'variants', 'images.media'])
            ->when($product->category_id, fn ($q, $categoryId) => $q->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$categoryId]))
            ->latest('id')
            ->limit(12)
            ->get();

        $related = $candidates->sortBy(fn (Product $p) => $p->inStock() ? 0 : 1)->take(self::RELATED_LIMIT)->values();

        return ApiResponse::success(StorefrontProductResource::collection($related));
    }
}
