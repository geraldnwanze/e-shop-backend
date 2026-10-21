<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AdjustInventoryRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

#[Group('Merchant: Products & inventory', weight: 22)]
class ProductInventoryController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $movements = InventoryMovement::query()->where('product_id', $product->getKey())->latest('id')->paginate(50);

        return ApiResponse::success($movements->getCollection()->map(fn (InventoryMovement $m) => $this->present($m))->all(), meta: [
            'currentPage' => $movements->currentPage(), 'total' => $movements->total(), 'perPage' => $movements->perPage(),
        ]);
    }

    public function store(AdjustInventoryRequest $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $this->authorize('update', $product);

        $variant = null;

        if ($request->validated('variant_id') !== null) {
            $variant = $product->variants()->where('id', $request->validated('variant_id'))->first()
                ?? throw ValidationException::withMessages(['variant_id' => ['Unknown variant for this product.']]);
        }

        $movement = $inventory->adjust(
            $product, $variant, (int) $request->validated('delta'),
            InventoryReason::from($request->validated('reason')), $request->validated('note'), $request->user()->getKey(),
        );

        if ($product->variants()->exists()) {
            $product->forceFill(['stock' => $product->variants()->sum('stock')])->save();
        }

        return ApiResponse::created($this->present($movement), 'Stock adjusted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InventoryMovement $m): array
    {
        return [
            'delta' => $m->delta, 'stockAfter' => $m->stock_after, 'reason' => $m->reason,
            'note' => $m->note, 'variantId' => $m->variant_id, 'createdAt' => $m->created_at,
        ];
    }
}
