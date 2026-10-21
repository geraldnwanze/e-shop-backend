<?php

namespace App\Services\Shipping;

use App\Exceptions\BaseApiException;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ShippingZoneService
{
    public const MAX_ZONES = 25;

    /**
     * Create or update a zone together with its delivery methods in one step.
     *
     * @param  array<string, mixed>  $data  validated ShippingZoneRequest input
     */
    public function save(?ShippingZone $zone, array $data): ShippingZone
    {
        return DB::transaction(function () use ($zone, $data): ShippingZone {
            if ($zone === null && ShippingZone::query()->count() >= self::MAX_ZONES) {
                throw BaseApiException::conflict('You can have up to '.self::MAX_ZONES.' shipping zones.');
            }

            $zone ??= new ShippingZone(['position' => (int) ShippingZone::query()->max('position') + 1]);
            $zone->fill(Arr::only($data, ['name', 'countries', 'is_active', 'position']));
            $zone->states = empty($data['states']) ? null : array_values($data['states']);
            $zone->save();
            $zone->refresh(); // pick up database defaults such as is_active

            $this->syncRates($zone, $data['rates'] ?? []);

            return $zone->load('rates');
        });
    }

    /**
     * Replace the zone's rates with the submitted list, keeping ids that are sent back so orders
     * that reference a rate keep pointing at it. An id belonging to another zone or store is ignored.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncRates(ShippingZone $zone, array $rows): void
    {
        $existing = $zone->rates()->get()->keyBy('id');
        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $rate = isset($row['id']) && $existing->has($row['id']) ? $existing->get($row['id']) : new ShippingRate;
            $rate->fill([
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'price' => $row['price'],
                'free_over' => $row['free_over'] ?? null,
                'is_active' => $row['is_active'] ?? true,
                'position' => $position,
            ]);
            $rate->shipping_zone_id = $zone->getKey();
            $rate->save();
            $keep[] = $rate->getKey();
        }

        $zone->rates()->whereNotIn('id', $keep)->delete();
    }
}
