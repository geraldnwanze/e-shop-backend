<?php

namespace App\Models;

use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery method in a zone: a flat price, optionally free once the cart reaches a minimum.
 */
#[Fillable(['name', 'description', 'price', 'free_over', 'is_active', 'position'])]
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'free_over' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * What this method costs for a cart, in minor units. The subtotal is the items total before any
     * discount code, so the price a customer is quoted never changes when they enter a code.
     */
    public function priceFor(int $subtotal): int
    {
        return $this->free_over !== null && $subtotal >= $this->free_over ? 0 : $this->price;
    }
}
