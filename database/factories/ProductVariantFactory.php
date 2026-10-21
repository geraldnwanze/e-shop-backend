<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        $size = fake()->randomElement(['S', 'M', 'L']);

        return [
            'tenant_id' => fn (array $a) => Product::query()->withoutGlobalScopes()->find($a['product_id'])->tenant_id,
            'product_id' => Product::factory(),
            'name' => $size,
            'sku' => strtoupper(fake()->unique()->bothify('VAR-????-####')),
            'options' => ['Size' => $size],
            'price' => null,
            'stock' => 10,
            'position' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn () => ['product_id' => $product->getKey(), 'tenant_id' => $product->tenant_id]);
    }
}
