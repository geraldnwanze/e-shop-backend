<?php

namespace App\Support\Tenancy;

use App\Models\Domain;
use App\Models\Tenant;

final class StorefrontUrl
{
    /**
     * Absolute URL on the tenant's own storefront (primary verified domain, else
     * the platform subdomain). Built server-side so callbacks can never be
     * redirected to a client-chosen host.
     */
    public static function for(Tenant $tenant, string $path = '/'): string
    {
        $host = app(TenantContext::class)->runAs($tenant, fn () => Domain::query()
            ->where('is_primary', true)->where('is_verified', true)->value('domain'))
            ?? $tenant->slug.'.'.config('tenancy.platform_domain');

        return config('tenancy.storefront_scheme').'://'.$host.'/'.ltrim($path, '/');
    }
}
