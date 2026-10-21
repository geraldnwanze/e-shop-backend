<?php

namespace Database\Factories;

use App\Models\StorePage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorePage>
 */
class StorePageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => ucfirst(fake()->unique()->words(2, true)),
            'slug' => fake()->unique()->slug(2),
            'body' => fake()->paragraph()."\n\n".fake()->paragraph(),
            'is_published' => true,
            'show_in_footer' => true,
            'position' => 0,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
