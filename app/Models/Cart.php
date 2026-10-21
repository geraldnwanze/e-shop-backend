<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cart UUID is the guest's bearer secret; carts bound to a customer also
 * require that customer to be authenticated.
 */
#[Fillable(['currency'])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (Cart $c): void {
            $c->expires_at ??= now()->addDays(30);
        });
    }

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'second_reminder_sent_at' => 'datetime', 'last_reminder_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
