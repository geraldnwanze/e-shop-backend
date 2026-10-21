<?php

namespace Database\Factories;

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $a) => Order::query()->withoutGlobalScopes()->find($a['order_id'])->tenant_id,
            'order_id' => Order::factory(),
            'provider' => PaymentProviderType::Paystack,
            'reference' => 'pay_'.Str::lower(Str::random(24)),
            'status' => PaymentStatus::Pending,
            'amount' => 500000,
            'currency' => 'NGN',
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn () => ['order_id' => $order->getKey(), 'tenant_id' => $order->tenant_id, 'amount' => $order->total, 'currency' => $order->currency]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Paid, 'paid_at' => now(), 'provider_reference' => '12345']);
    }
}
