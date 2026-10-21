<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\ShippingZoneRequest;
use App\Http\Resources\ShippingZoneResource;
use App\Models\ShippingZone;
use App\Services\Shipping\ShippingZoneService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Shipping', weight: 25)]
class ShippingZoneController extends Controller
{
    public function __construct(private readonly ShippingZoneService $zones) {}

    /**
     * Every zone with its delivery methods, in the order they are matched.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ShippingZone::class);

        return ApiResponse::success(ShippingZoneResource::collection(ShippingZone::query()->with('rates')->orderBy('position')->orderBy('created_at')->get()));
    }

    public function store(ShippingZoneRequest $request): JsonResponse
    {
        $this->authorize('create', ShippingZone::class);

        return ApiResponse::created(ShippingZoneResource::make($this->zones->save(null, $request->validated())), 'Shipping zone created.');
    }

    public function show(ShippingZone $shippingZone): JsonResponse
    {
        $this->authorize('view', $shippingZone);

        return ApiResponse::success(ShippingZoneResource::make($shippingZone->load('rates')));
    }

    public function update(ShippingZoneRequest $request, ShippingZone $shippingZone): JsonResponse
    {
        $this->authorize('update', $shippingZone);

        return ApiResponse::success(ShippingZoneResource::make($this->zones->save($shippingZone, $request->validated())), 'Shipping zone updated.');
    }

    public function destroy(ShippingZone $shippingZone): JsonResponse
    {
        $this->authorize('delete', $shippingZone);

        $shippingZone->delete();

        return ApiResponse::noContent('Shipping zone deleted.');
    }
}
