<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;

/**
 * Everything the billing page needs in one response: where the store stands, what it can buy, and what it has paid.
 */
class BillingSummary
{
    public function __construct(private readonly BillingService $billing, private readonly BillingPricing $pricing, private readonly BillingGateway $gateway) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant): array
    {
        $subscription = $this->billing->current($tenant);

        return [
            'billingAvailable' => $this->gateway->configured(),
            'currency' => strtoupper((string) config('billing.currency')),
            'subscription' => $subscription === null ? null : $this->subscription($subscription),
            'plans' => Plan::query()->where('is_active', true)->orderBy('position')->get()
                ->filter(fn (Plan $plan) => $this->pricing->isPurchasable($plan))->values()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthlyPrice' => $this->pricing->monthly($plan),
                    'limits' => $plan->limits,
                    'features' => $plan->features ?? [],
                    'options' => $this->pricing->options($plan),
                    'current' => $subscription !== null && $subscription->plan_id === $plan->id && $subscription->isEntitled(),
                ])->all(),
            'invoices' => SubscriptionInvoice::query()->with('plan:id,name')->latest()->limit(12)->get()->map(fn (SubscriptionInvoice $invoice) => [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'plan' => $invoice->plan?->name,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'periodMonths' => $invoice->period_months,
                'status' => $invoice->status,
                'paidAt' => $invoice->paid_at,
                'periodEndsAt' => $invoice->period_ends_at,
                'createdAt' => $invoice->created_at,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        $trialDaysLeft = $subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at !== null
            ? max(0, (int) ceil(now()->floatDiffInDays($subscription->trial_ends_at, false)))
            : null;

        return [
            'status' => $subscription->status,
            'plan' => ['id' => $subscription->plan->id, 'name' => $subscription->plan->name, 'slug' => $subscription->plan->slug, 'limits' => $subscription->plan->limits, 'features' => $subscription->plan->features ?? []],
            'selfServe' => $subscription->isSelfServe(),
            'billingPeriod' => $subscription->billing_period,
            'autoRenew' => $subscription->auto_renew,
            'trialEndsAt' => $subscription->trial_ends_at,
            'trialDaysLeft' => $trialDaysLeft,
            'currentPeriodEndsAt' => $subscription->current_period_ends_at,
            'card' => $subscription->card_last4 ? ['brand' => $subscription->card_brand, 'last4' => $subscription->card_last4] : null,
            'failedAttempts' => $subscription->failed_attempts,
            'nextAttemptAt' => $subscription->next_attempt_at,
            'free' => $subscription->plan->price === 0,
        ];
    }
}
