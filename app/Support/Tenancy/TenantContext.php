<?php

namespace App\Support\Tenancy;

use App\Exceptions\MissingTenantContextException;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Request-scoped holder for the current tenant.
 *
 * Registered with `scoped()` so Octane / queue workers reset it between
 * requests and jobs. Jobs must carry their tenant id explicitly and re-enter
 * the context with runAs(); nothing is ever inherited implicitly.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $bypassed = false;

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function requireTenant(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContextException;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        Context::add('tenant_id', $tenant->id);
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->bypassed = false;
        Context::forget('tenant_id');
    }

    /**
     * Platform-admin requests only (see EnsurePlatformAdminMiddleware): tenant-scoped
     * queries become unscoped for the rest of the request. Services that rely on the
     * scope must be run inside runAs() instead.
     */
    public function enableBypass(): void
    {
        $this->bypassed = true;
    }

    /**
     * True only inside an explicit platform-level cross-tenant operation.
     */
    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    /**
     * Run a callback inside a tenant, restoring the previous state afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypassed;

        $this->set($tenant);
        $this->bypassed = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->bypassed = $previousBypass;
            $previousTenant ? Context::add('tenant_id', $previousTenant->id) : Context::forget('tenant_id');
        }
    }

    /**
     * Explicit, auditable escape hatch for platform-level cross-tenant work.
     * Callers MUST have authorised the actor as a platform administrator.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutTenantScope(Closure $callback): mixed
    {
        $previous = $this->bypassed;
        $this->bypassed = true;

        try {
            return $callback();
        } finally {
            $this->bypassed = $previous;
        }
    }
}
