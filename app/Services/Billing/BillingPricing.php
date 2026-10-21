<?php

namespace App\Services\Billing;

use App\Enums\PlanInterval;
use App\Models\Plan;

/**
 * Turns a plan's list price into what a merchant pays for a given billing period.
 * The plan's price is per month (or per year for yearly plans); longer periods earn a discount.
 */
class BillingPricing
{
    /**
     * @return array<int, int> months => discount percent
     */
    public function periods(): array
    {
        return (array) config('billing.periods');
    }

    public function supports(int $months): bool
    {
        return array_key_exists($months, $this->periods());
    }

    /**
     * Whether a merchant can buy this plan online (the free plan and other currencies cannot be bought).
     */
    public function isPurchasable(Plan $plan): bool
    {
        return $plan->is_active && $plan->price > 0 && strtoupper($plan->currency) === strtoupper((string) config('billing.currency'));
    }

    /**
     * List price per month, in minor units.
     */
    public function monthly(Plan $plan): int
    {
        return $plan->interval === PlanInterval::Yearly ? (int) round($plan->price / 12) : $plan->price;
    }

    /**
     * Total for the period, in minor units, rounded to whole major units so prices look clean.
     */
    public function amount(Plan $plan, int $months): int
    {
        $discount = $this->periods()[$months] ?? 0;
        $raw = $this->monthly($plan) * $months * (100 - $discount) / 100;

        return (int) (round($raw / 100) * 100);
    }

    /**
     * @return list<array{months: int, amount: int, perMonth: int, discountPercent: int, savings: int}>
     */
    public function options(Plan $plan): array
    {
        $options = [];

        foreach ($this->periods() as $months => $discount) {
            $amount = $this->amount($plan, $months);
            $options[] = [
                'months' => $months,
                'amount' => $amount,
                'perMonth' => (int) round($amount / $months),
                'discountPercent' => $discount,
                'savings' => max(0, $this->monthly($plan) * $months - $amount),
            ];
        }

        return $options;
    }
}
