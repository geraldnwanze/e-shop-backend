<?php

namespace App\Models;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Support\Audit;
use App\Traits\Audited;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'status', 'suspended_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use Audited, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * Whether the store may take orders: at least one of its owners has verified their email
     * (unless the platform has turned the requirement off). Staff verification does not count.
     */
    public function acceptsOrders(): bool
    {
        if (! config('tenancy.require_verified_email_to_sell')) {
            return true;
        }

        return $this->users()->where('role', UserRole::MerchantOwner->value)->whereNotNull('email_verified_at')->exists();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasOne<Store, $this>
     */
    public function store(): HasOne
    {
        return $this->hasOne(Store::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class)->withoutGlobalScopes();
    }

    /**
     * The most recent subscription (live or not).
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScopes()->latest()->latest('id');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function auditAction(string $event, array $changes): string
    {
        if ($event === 'updated' && array_key_exists('suspended_at', $changes)) {
            return $this->suspended_at !== null ? 'tenant.suspended' : 'tenant.reactivated';
        }

        return Audit::noun($this).'.'.$event;
    }
}
