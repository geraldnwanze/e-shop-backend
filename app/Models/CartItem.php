<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'variant_id', 'quantity'])]
class CartItem extends Model
{
    use BelongsToTenant, HasUuids;

    /** Changing a line counts as cart activity, which is what abandoned-cart reminders wait on. */
    protected $touches = ['cart'];

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function unitPrice(): int
    {
        return $this->variant?->price ?? $this->product->price;
    }

    public function lineTotal(): int
    {
        return $this->unitPrice() * $this->quantity;
    }

    /**
     * Still purchasable at the requested quantity right now.
     */
    public function isAvailable(): bool
    {
        if (! $this->product->isPurchasable()) {
            return false;
        }

        if (! $this->product->track_inventory) {
            return true;
        }

        return ($this->variant ?? $this->product)->stock >= $this->quantity;
    }
}
