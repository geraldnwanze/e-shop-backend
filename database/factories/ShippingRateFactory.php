<?php

namespace Database\Factories;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attrs) => ShippingZone::query()->withoutGlobalScopes()->find($attrs['shipping_zone_id'])->tenant_id,
            'shipping_zone_id' => ShippingZone::factory(),
            'name' => 'Standard delivery',
            'description' => null,
            'price' => 250000,
            'free_over' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function forZone(ShippingZone $zone): static
    {
        return $this->state(fn () => ['shipping_zone_id' => $zone->getKey(), 'tenant_id' => $zone->tenant_id]);
    }
}
