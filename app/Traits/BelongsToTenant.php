<?php

namespace App\Traits;

use App\Exceptions\MissingTenantContextException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as tenant-owned: global TenantScope + automatic tenant_id.
 * Never put `tenant_id` in $fillable; it is always assigned server-side.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($model->tenant_id === null) {
                $model->tenant_id = $context->id() ?? throw new MissingTenantContextException;

                return;
            }

            if (! $context->isBypassed() && $context->hasTenant() && (string) $model->tenant_id !== $context->id()) {
                throw new LogicException('Refusing to create a record for a tenant other than the current one.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withoutGlobalScopes();
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
