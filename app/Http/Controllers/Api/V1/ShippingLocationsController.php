<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ShippingZone;
use App\Support\ApiResponse;
use App\Support\Shipping\Locations;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Shipping', weight: 25)]
class ShippingLocationsController extends Controller
{
    /**
     * The countries and Nigerian states a zone can be built from.
     */
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', ShippingZone::class);

        return ApiResponse::success([
            'countries' => collect(Locations::COUNTRIES)->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])->values(),
            'states' => Locations::NIGERIAN_STATES,
            'everywhere' => Locations::EVERYWHERE,
        ]);
    }
}
