<?php

namespace App\Services\Shipping;

use App\Exceptions\BaseApiException;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Validation\ValidationException;

/**
 * Works out what delivery a store offers an address and what it costs. Runs inside a tenant
 * context, so a store only ever sees its own zones.
 */
class ShippingService
{
    /**
     * Whether the store has set up delivery zones. Stores that have not keep charging their single
     * flat rate, so existing stores carry on working until the merchant opts in.
     */
    public function isConfigured(): bool
    {
        return ShippingZone::query()->where('is_active', true)->exists();
    }

    /**
     * The most specific active zone covering the address: a state list beats a country list beats
     * "everywhere else". Ties go to the zone the merchant ordered first.
     */
    public function zoneFor(string $country, ?string $state): ?ShippingZone
    {
        return ShippingZone::query()->where('is_active', true)->with(['rates' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('position')->orderBy('created_at')->get()
            ->filter(fn (ShippingZone $zone) => $zone->covers($country, $state))
            ->sortByDesc(fn (ShippingZone $zone) => $zone->specificity())
            ->first();
    }

    /**
     * The delivery methods available for an address, priced for this cart.
     *
     * @return list<array{rate: ShippingRate, price: int}>
     */
    public function options(string $country, ?string $state, int $subtotal): array
    {
        $zone = $this->zoneFor($country, $state);

        if ($zone === null) {
            return [];
        }

        return $zone->rates->map(fn (ShippingRate $rate) => ['rate' => $rate, 'price' => $rate->priceFor($subtotal)])->all();
    }

    /**
     * Settle the delivery method for an order from the address and the chosen rate. Never trusts a
     * client-supplied price: only the rate id is accepted, and it must be one this address qualifies for.
     *
     * @param  array<string, mixed>  $address
     * @return array{rate: ShippingRate, price: int}
     */
    public function resolve(array $address, ?string $rateId, int $subtotal): array
    {
        $options = $this->options((string) ($address['country'] ?? ''), $address['state'] ?? null, $subtotal);

        if ($options === []) {
            throw BaseApiException::shippingUnavailable();
        }

        if ($rateId === null && count($options) === 1) {
            return $options[0];
        }

        if ($rateId === null) {
            throw ValidationException::withMessages(['shipping_rate_id' => ['Choose a delivery method.']]);
        }

        foreach ($options as $option) {
            if ($option['rate']->getKey() === $rateId) {
                return $option;
            }
        }

        throw ValidationException::withMessages(['shipping_rate_id' => ['That delivery method is not available for this address.']]);
    }
}
