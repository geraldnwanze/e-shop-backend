<?php

namespace App\Http\Resources;

use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShippingZone
 */
class ShippingZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'countries' => $this->countries,
            'states' => $this->states ?? [],
            'isActive' => $this->is_active,
            'position' => $this->position,
            'rates' => $this->whenLoaded('rates', fn () => $this->rates->map(fn ($rate) => [
                'id' => $rate->id,
                'name' => $rate->name,
                'description' => $rate->description,
                'price' => $rate->price,
                'freeOver' => $rate->free_over,
                'isActive' => $rate->is_active,
                'position' => $rate->position,
            ])->values()),
        ];
    }
}
