<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $address = [
            'first_name' => 'Ada', 'last_name' => 'Obi', 'line1' => '1 Marina', 'city' => 'Lagos',
            'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG', 'phone' => '+2348000000000',
        ];

        return [
            'tenant_id' => Tenant::factory(),
            'order_number' => 'ORD-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_email' => fake()->safeEmail(),
            'customer_name' => fake()->name(),
            'status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'currency' => 'NGN',
            'subtotal' => 500000,
            'total' => 500000,
            'shipping_address' => $address,
            'billing_address' => $address,
            'placed_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }
}
