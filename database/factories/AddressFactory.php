<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $a) => Customer::query()->withoutGlobalScopes()->find($a['customer_id'])->tenant_id,
            'customer_id' => Customer::factory(),
            'label' => 'Home',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Lagos',
            'postal_code' => '100001',
            'country' => 'NG',
            'phone' => fake()->numerify('+23480########'),
            'is_default' => false,
        ];
    }
}
