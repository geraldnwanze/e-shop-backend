<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WishlistItem>
 */
class WishlistItemFactory extends Factory
{
    public function definition(): array
    {
        return ['tenant_id' => Tenant::factory(), 'customer_id' => Customer::factory(), 'product_id' => Product::factory()];
    }
}
