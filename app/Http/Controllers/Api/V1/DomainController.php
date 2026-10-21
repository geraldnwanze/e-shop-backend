<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Domain\AddDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Domains\DomainService;
use App\Services\Domains\DomainVerificationService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Domains', weight: 25)]
class DomainController extends Controller
{
    public function __construct(
        private readonly DomainService $domains,
        private readonly DomainVerificationService $verification,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Domain::class);

        return ApiResponse::success(DomainResource::collection(Domain::query()->orderByDesc('is_primary')->orderBy('id')->get()));
    }

    public function store(AddDomainRequest $request): JsonResponse
    {
        $this->authorize('create', Domain::class);

        return ApiResponse::created(
            DomainResource::make($this->domains->addCustomDomain($request->validated('domain'))),
            'Domain added. Add the DNS records below, then verify.',
        );
    }

    public function show(Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        return ApiResponse::success(DomainResource::make($domain));
    }

    public function verify(Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        $domain = $this->verification->verify($domain);

        return ApiResponse::success(DomainResource::make($domain), $domain->is_verified ? 'Domain verified.' : 'Domain could not be verified yet.');
    }

    public function makePrimary(Domain $domain): JsonResponse
    {
        $this->authorize('view', $domain);

        return ApiResponse::success(DomainResource::make($this->domains->makePrimary($domain)), 'Primary domain updated.');
    }

    public function destroy(Domain $domain): JsonResponse
    {
        $this->authorize('delete', $domain);

        $this->domains->remove($domain);

        return ApiResponse::noContent('Domain removed.');
    }
}
