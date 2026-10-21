<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
class ProductReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'product_id' => Product::factory(),
            'customer_id' => Customer::factory(),
            'rating' => fake()->numberBetween(3, 5),
            'title' => fake()->sentence(3),
            'body' => fake()->paragraph(),
            'author_name' => 'Ada O.',
            'status' => ProductReview::STATUS_PUBLISHED,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
