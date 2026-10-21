<?php

namespace Database\Factories;

use App\Enums\ThemeStatus;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => $name.'-'.fake()->unique()->numerify('##'),
            'description' => fake()->sentence(),
            'status' => ThemeStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ThemeStatus::Inactive]);
    }
}
