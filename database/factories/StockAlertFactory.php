<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAlert>
 */
class StockAlertFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'product_id' => Product::factory(), 'variant_id' => null, 'email' => fake()->unique()->safeEmail()];
    }
}
