<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Discount>
 */
class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->unique()->bothify('SAVE-####')),
            'type' => DiscountType::Percentage,
            'value' => 10,
            'min_subtotal' => 0,
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    public function fixed(int $amount): static
    {
        return $this->state(fn () => ['type' => DiscountType::Fixed, 'value' => $amount]);
    }
}
