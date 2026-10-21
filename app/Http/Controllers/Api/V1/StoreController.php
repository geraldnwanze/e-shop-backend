<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\UpdateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Store', weight: 21)]
class StoreController extends Controller
{
    public function show(): JsonResponse
    {
        $store = Store::query()->with('theme')->firstOrFail();

        $this->authorize('view', $store);

        return ApiResponse::success([
            ...StoreResource::make($store)->resolve(),
            'acceptingOrders' => app(TenantContext::class)->requireTenant()->acceptsOrders(),
        ]);
    }

    /**
     * The slug (and therefore the subdomain) is intentionally immutable.
     */
    public function update(UpdateStoreRequest $request): JsonResponse
    {
        $store = Store::query()->firstOrFail();

        $this->authorize('update', $store);

        $store->update($request->validated());

        return ApiResponse::success(StoreResource::make($store->load('theme')), 'Store updated.');
    }
}
