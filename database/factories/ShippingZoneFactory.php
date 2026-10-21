<?php

namespace Database\Factories;

use App\Models\ShippingZone;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => ucfirst(fake()->unique()->word()).' zone',
            'countries' => ['NG'],
            'states' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    /**
     * @param  list<string>  $states
     */
    public function inStates(array $states): static
    {
        return $this->state(fn () => ['countries' => ['NG'], 'states' => $states]);
    }

    public function everywhereElse(): static
    {
        return $this->state(fn () => ['countries' => ['*'], 'states' => null]);
    }
}
