<?php

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'status' => StoreStatus::Active,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ]);
    }
}
