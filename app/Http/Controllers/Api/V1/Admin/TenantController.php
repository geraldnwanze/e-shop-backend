<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Tenants\SetTenantStatusAction;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Platform admin: Tenants & stores', weight: 40)]
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tenant::class);

        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_column(TenantStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenants = Tenant::query()
            ->with(['store', 'subscription.plan'])
            ->withCount('users')
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['search'] ?? null, fn ($q, $term) => $q->where(
                fn ($q) => $q->whereLike('name', Like::contains($term))->orWhereLike('slug', Like::contains($term)),
            ))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return ApiResponse::success(TenantResource::collection($tenants));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        return ApiResponse::success(TenantResource::make($tenant->load(['store', 'domains', 'users', 'subscription.plan'])));
    }

    public function suspend(Tenant $tenant, SetTenantStatusAction $action): JsonResponse
    {
        $this->authorize('suspend', $tenant);

        return ApiResponse::success(TenantResource::make($action->suspend($tenant)->load(['store', 'domains'])), 'Tenant suspended.');
    }

    public function activate(Tenant $tenant, SetTenantStatusAction $action): JsonResponse
    {
        $this->authorize('activate', $tenant);

        return ApiResponse::success(TenantResource::make($action->activate($tenant)->load(['store', 'domains'])), 'Tenant activated.');
    }
}
