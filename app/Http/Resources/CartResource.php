<?php

namespace App\Http\Resources;

use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prices are always recomputed from current catalogue data.
 *
 * @mixin Cart
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items;

        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'productId' => $item->product->id,
                'variantId' => $item->variant?->id,
                'name' => $item->product->name,
                'slug' => $item->product->slug,
                'variantName' => $item->variant?->name,
                'image' => $item->product->images->first()?->media?->url,
                'unitPrice' => $item->unitPrice(),
                'quantity' => $item->quantity,
                'lineTotal' => $item->lineTotal(),
                'available' => $item->isAvailable(),
            ])->values(),
            'itemCount' => $items->sum('quantity'),
            'subtotal' => $items->sum(fn ($i) => $i->lineTotal()),
        ];
    }
}
