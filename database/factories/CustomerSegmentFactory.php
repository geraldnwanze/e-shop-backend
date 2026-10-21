<?php

namespace Database\Factories;

use App\Models\CustomerSegment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerSegment>
 */
class CustomerSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => null,
            'rules' => ['min_orders' => 2],
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function rules(array $rules): static
    {
        return $this->state(fn () => ['rules' => $rules]);
    }
}
