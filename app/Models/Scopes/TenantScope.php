<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to the current tenant. Fails closed: with no tenant in
 * context (and no explicit platform bypass) a query matches nothing.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $column = $model->qualifyColumn('tenant_id');
        $tenantId = $context->id();

        $tenantId === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($column, $tenantId);
    }
}
