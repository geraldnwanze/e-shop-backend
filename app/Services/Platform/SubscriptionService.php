<?php

namespace App\Services\Platform;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Put a tenant on a plan. Any live subscription is cancelled first, so a
     * tenant has at most one entitled subscription at a time.
     */
    public function assign(Tenant $tenant, Plan $plan, SubscriptionStatus $status = SubscriptionStatus::Active, ?CarbonInterface $trialEndsAt = null, ?CarbonInterface $periodEndsAt = null): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $status, $trialEndsAt, $periodEndsAt): Subscription {
            Subscription::withoutTenantScope()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('status', array_map(fn ($s) => $s->value, SubscriptionStatus::entitled()))
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);

            $subscription = new Subscription([
                'plan_id' => $plan->getKey(),
                'status' => $status,
                'trial_ends_at' => $trialEndsAt,
                'current_period_ends_at' => $periodEndsAt,
            ]);
            $subscription->tenant_id = $tenant->getKey();
            $subscription->save();

            return $subscription->load('plan');
        });
    }
}
