<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DomainStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Domain;
use App\Models\Order;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Platform admin: Tenants & stores', weight: 40)]
class OverviewController extends Controller
{
    /**
     * Platform-wide figures. Runs under the platform-admin tenant-scope bypass,
     * so every model query below is deliberately cross-tenant.
     */
    public function __invoke(): JsonResponse
    {
        $paid = [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];

        $mrr = Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('plans.currency as currency, plans.interval as plan_interval, sum(plans.price) as total')
            ->groupBy('plans.currency', 'plans.interval')->get()
            ->groupBy('currency')
            ->map(fn ($rows, $currency) => [
                'currency' => $currency,
                'amount' => (int) round($rows->sum(fn ($r) => $r->plan_interval === PlanInterval::Yearly->value ? $r->total / 12 : $r->total)),
            ])->values()->all();

        return ApiResponse::success([
            'tenants' => [
                'total' => Tenant::query()->count(),
                'active' => Tenant::query()->where('status', TenantStatus::Active->value)->count(),
                'suspended' => Tenant::query()->where('status', TenantStatus::Suspended->value)->count(),
                'newLast30Days' => Tenant::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'stores' => Store::query()->count(),
            'merchantUsers' => User::query()->whereNotNull('tenant_id')->count(),
            'orders' => Order::query()->count(),
            'gmv' => Order::query()->whereIn('payment_status', $paid)->selectRaw('currency, sum(total) as total')->groupBy('currency')->get()
                ->map(fn ($r) => ['currency' => $r->currency, 'amount' => (int) $r->total])->all(),
            'activeSubscriptions' => Subscription::query()->where('status', SubscriptionStatus::Active->value)->count(),
            'mrr' => $mrr,
            'domainsNeedingAttention' => Domain::query()->whereIn('status', [DomainStatus::Pending->value, DomainStatus::Failed->value])->count(),
            'recentTenants' => TenantResource::collection(Tenant::query()->with(['store', 'subscription.plan'])->latest('id')->limit(5)->get())->resolve(),
        ]);
    }
}
