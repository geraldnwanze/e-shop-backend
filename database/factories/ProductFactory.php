<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->paragraph(),
            'short_description' => fake()->sentence(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-????-####')),
            'price' => fake()->numberBetween(100, 5000) * 100,
            'status' => ProductStatus::Active,
            'stock' => 50,
            'track_inventory' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Draft]);
    }
}
