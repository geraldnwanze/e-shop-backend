<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Storefront shopper. Tenant-scoped: a token issued for one tenant's customer
 * cannot even be loaded under another tenant's context.
 */
#[Fillable(['email', 'first_name', 'last_name', 'phone'])]
#[Hidden(['password'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'accepts_marketing' => 'boolean', 'marketing_opted_in_at' => 'datetime'];
    }

    /**
     * Record that the shopper ticked "email me news and offers". There is deliberately no opposite
     * here: leaving is done through the unsubscribe link, which applies to every future campaign.
     */
    public function optInToMarketing(): void
    {
        if (! $this->accepts_marketing) {
            $this->forceFill(['accepts_marketing' => true, 'marketing_opted_in_at' => now()])->save();
        }
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function hasAccount(): bool
    {
        return $this->password !== null;
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }
}
