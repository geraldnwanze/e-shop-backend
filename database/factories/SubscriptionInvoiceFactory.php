<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'reference' => 'bill_'.Str::lower(Str::random(24)),
            'purpose' => 'subscribe',
            'period_months' => 1,
            'amount' => 500000,
            'currency' => 'NGN',
            'status' => InvoiceStatus::Pending,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
    }
}
