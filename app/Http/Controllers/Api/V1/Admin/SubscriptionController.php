<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubscriptionStatus;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignSubscriptionRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Platform\SubscriptionService;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

#[Group('Platform admin: Plans & subscriptions', weight: 42)]
class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'plan' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $subscriptions = Subscription::query()->with(['plan', 'tenant'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['plan'] ?? null, fn ($q, $v) => $q->whereHas('plan', fn ($p) => $p->where('id', $v)))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereHas('tenant', fn ($t) => $t
                ->whereLike('name', Like::contains($term))->orWhereLike('slug', Like::contains($term))))
            ->latest('id')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(SubscriptionResource::collection($subscriptions));
    }

    public function assign(AssignSubscriptionRequest $request, Tenant $tenant, SubscriptionService $service): JsonResponse
    {
        $plan = Plan::query()->where('id', $request->validated('plan_id'))->firstOrFail();
        $status = SubscriptionStatus::from($request->validated('status', SubscriptionStatus::Active->value));

        $subscription = $service->assign(
            $tenant, $plan, $status,
            $request->date('trial_ends_at'),
            $request->date('current_period_ends_at') ?? ($status === SubscriptionStatus::Active ? Carbon::now()->addMonth() : null),
        );

        return ApiResponse::created(SubscriptionResource::make($subscription->load('tenant')), 'Plan assigned.');
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw BaseApiException::conflict('A cancelled subscription cannot be changed or reactivated. Assign a plan to the store instead.');
        }

        $subscription->fill($request->validated());

        if ($subscription->isDirty('status')) {
            $subscription->cancelled_at = $subscription->status === SubscriptionStatus::Cancelled ? now() : null;
        }

        $subscription->save();

        return ApiResponse::success(SubscriptionResource::make($subscription->load(['plan', 'tenant'])), 'Subscription updated.');
    }
}
