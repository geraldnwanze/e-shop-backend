<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\StockNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view: no cost price, no exact stock levels.
 *
 * @mixin Product
 */
class StorefrontProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notice = StockNotice::threshold($request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'shortDescription' => $this->short_description,
            'price' => $this->price,
            'compareAtPrice' => $this->compare_at_price,
            'inStock' => $this->inStock(),
            'unitsLeft' => $this->hasVariants() ? null : Product::unitsLeftNotice($this->track_inventory, $this->stock, $notice),
            'ratingAverage' => (float) $this->rating_average,
            'ratingCount' => (int) $this->rating_count,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug] : null),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'options' => $v->options,
                'price' => $v->price ?? $this->price,
                'inStock' => ! $this->track_inventory || $v->stock > 0,
                'unitsLeft' => Product::unitsLeftNotice($this->track_inventory, $v->stock, $notice),
            ])),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
