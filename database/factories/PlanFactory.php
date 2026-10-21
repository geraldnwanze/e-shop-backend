<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => strtolower($name).'-'.fake()->unique()->numerify('##'),
            'description' => fake()->sentence(),
            'price' => 500000,
            'currency' => 'NGN',
            'interval' => PlanInterval::Monthly,
            'limits' => ['products' => null, 'custom_domains' => 3],
            'features' => ['Email support'],
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * @param  array{products?: ?int, custom_domains?: ?int}  $limits
     */
    public function limits(array $limits): static
    {
        return $this->state(fn () => ['limits' => $limits + ['products' => null, 'custom_domains' => null]]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
