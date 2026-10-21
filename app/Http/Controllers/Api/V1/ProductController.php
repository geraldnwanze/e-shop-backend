<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Merchant: Products & inventory', weight: 22)]
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ProductStatus::class)],
            'category' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['category', 'variants', 'images.media'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->whereHas('category', fn ($c) => $c->where('id', $v)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('name', Like::contains($term))
                ->orWhereLike('sku', Like::contains($term))
                ->orWhereHas('variants', fn ($v) => $v->whereLike('sku', Like::contains($term)))))
            ->when($request->boolean('low_stock'), fn ($q) => $q->where('track_inventory', true)->where('stock', '<=', 5))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        return ApiResponse::created(ProductResource::make($this->products->create($request->validated())), 'Product created.');
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return ApiResponse::success(ProductResource::make($product->load(['category', 'variants', 'images.media'])));
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        return ApiResponse::success(ProductResource::make($this->products->update($product, $request->validated())), 'Product updated.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return ApiResponse::noContent('Product deleted.');
    }
}
