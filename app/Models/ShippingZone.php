<?php

namespace App\Models;

use App\Support\Shipping\Locations;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named area a store delivers to, with the delivery methods (rates) offered there.
 */
#[Fillable(['name', 'countries', 'states', 'is_active', 'position'])]
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'states' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->orderBy('position')->orderBy('created_at');
    }

    /**
     * How tightly this zone targets an address: a state list beats a country list beats "everywhere else".
     * The most specific matching zone wins, so "Lagos" overrides "Rest of Nigeria".
     */
    public function specificity(): int
    {
        return match (true) {
            ! empty($this->states) => 3,
            in_array(Locations::EVERYWHERE, $this->countries ?? [], true) => 1,
            default => 2,
        };
    }

    public function covers(string $country, ?string $state): bool
    {
        $countries = $this->countries ?? [];

        if (! in_array(Locations::EVERYWHERE, $countries, true) && ! in_array(strtoupper($country), $countries, true)) {
            return false;
        }

        if (empty($this->states)) {
            return true;
        }

        $canonical = Locations::canonicalState($state);

        return $canonical !== null && in_array($canonical, $this->states, true);
    }
}
