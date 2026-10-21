<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Merchant view: includes cost price and exact stock.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'shortDescription' => $this->short_description,
            'sku' => $this->sku,
            'price' => $this->price,
            'compareAtPrice' => $this->compare_at_price,
            'costPrice' => $this->cost_price,
            'status' => $this->status,
            'stock' => $this->stock,
            'ratingAverage' => (float) $this->rating_average,
            'ratingCount' => (int) $this->rating_count,
            'trackInventory' => $this->track_inventory,
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
