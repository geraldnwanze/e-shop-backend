<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\ShippingOptionsRequest;
use App\Models\Cart;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Services\Shipping\ShippingService;
use App\Services\Themes\StoreCustomizationService;
use App\Support\ApiResponse;
use App\Support\Shipping\Locations;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Storefront: Checkout & payments', weight: 12)]
class ShippingController extends Controller
{
    public function __construct(private readonly ShippingService $shipping, private readonly StoreCustomizationService $customization) {}

    /**
     * Where this store delivers, for the checkout's country and state pickers.
     */
    public function locations(): JsonResponse
    {
        $zones = ShippingZone::query()->where('is_active', true)->get();
        $configured = $zones->isNotEmpty();
        $codes = ! $configured || $zones->contains(fn (ShippingZone $z) => in_array(Locations::EVERYWHERE, $z->countries, true))
            ? Locations::countryCodes()
            : array_values(array_intersect(Locations::countryCodes(), $zones->pluck('countries')->flatten()->unique()->all()));

        return ApiResponse::success([
            'configured' => $configured,
            'countries' => collect($codes)->map(fn (string $code) => ['code' => $code, 'name' => Locations::COUNTRIES[$code]])->values(),
            'states' => Locations::NIGERIAN_STATES,
        ]);
    }

    /**
     * The delivery methods and prices available for an address, for this cart.
     */
    public function options(ShippingOptionsRequest $request): JsonResponse
    {
        $cart = Cart::query()->whereKey($request->validated('cart_id'))->firstOrFail();

        if (! $this->shipping->isConfigured()) {
            $settings = $this->customization->settings(Store::query()->firstOrFail())->settings;

            return ApiResponse::success(['configured' => false, 'available' => true, 'flatRate' => (int) ($settings['checkout']['shipping_flat_rate'] ?? 0), 'options' => [], 'message' => null]);
        }

        $subtotal = $cart->items()->with(['product', 'variant'])->get()->sum(fn ($item) => $item->lineTotal());
        $options = $this->shipping->options($request->validated('country'), $request->validated('state'), $subtotal);

        return ApiResponse::success([
            'configured' => true,
            'available' => $options !== [],
            'flatRate' => null,
            'options' => collect($options)->map(fn (array $o) => [
                'id' => $o['rate']->id,
                'name' => $o['rate']->name,
                'description' => $o['rate']->description,
                'price' => $o['price'],
                'free' => $o['price'] === 0,
                'freeOver' => $o['rate']->free_over,
            ])->values(),
            'message' => $options === [] ? "Sorry, we don't deliver to that address yet." : null,
        ]);
    }
}
