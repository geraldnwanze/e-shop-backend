<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Domains\DomainService;
use App\Services\Domains\DomainVerificationService;
use App\Support\ApiResponse;
use App\Support\Like;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Platform admin: Themes, domains & settings', weight: 43)]
class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(DomainStatus::class)],
            'type' => ['nullable', Rule::enum(DomainType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $domains = Domain::query()->with('tenant')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->whereLike('domain', Like::contains(strtolower($term))))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->latest('id')->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(DomainResource::collection($domains));
    }

    /**
     * Re-run the real DNS check. An administrator cannot mark a domain verified by hand.
     */
    public function verify(Domain $domain, DomainVerificationService $verification): JsonResponse
    {

        return ApiResponse::success(DomainResource::make($verification->verify($domain)->load('tenant')));
    }

    public function destroy(Domain $domain, DomainService $domains, TenantContext $context): JsonResponse
    {
        $tenant = $domain->tenant;

        // DomainService relies on the tenant scope (primary re-assignment), so run it as the owning tenant.
        $context->runAs($tenant, fn () => $domains->remove($domain));

        return ApiResponse::noContent('Domain removed.');
    }
}
