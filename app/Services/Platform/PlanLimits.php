<?php

namespace App\Services\Platform;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Resolves what a tenant's plan allows. No (entitled) subscription means the
 * configured defaults apply: unlimited products, config-driven custom domains.
 */
class PlanLimits
{
    public function limit(Tenant $tenant, string $key): ?int
    {
        $subscription = Subscription::withoutTenantScope()
            ->where('tenant_id', $tenant->getKey())
            ->with('plan')
            ->latest('id')
            ->first();

        if ($subscription !== null && $subscription->isEntitled()) {
            return $subscription->plan->limit($key);
        }

        return $key === 'custom_domains' ? (int) config('tenancy.custom_domains.max_per_tenant') : null;
    }

    /**
     * @throws BaseApiException when adding one more would exceed the limit
     */
    public function assertCanAdd(Tenant $tenant, string $key, int $currentCount, string $label): void
    {
        $limit = $this->limit($tenant, $key);

        if ($limit !== null && $currentCount >= $limit) {
            throw new BaseApiException(ApiErrorCodeEnum::PlanLimitReached, "Your plan allows up to {$limit} {$label}. Upgrade to add more.");
        }
    }
}
