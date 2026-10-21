<?php

namespace App\Support\Tenancy;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Single source of truth for hostname -> tenant resolution.
 *
 * Only verified domains resolve. Cache entries are keyed by the normalised
 * hostname and hold only a tenant id, so a hit can never cross hosts.
 */
class TenantResolver
{
    private const MISS = 0;

    public function __construct(private readonly Cache $cache) {}

    public function resolve(string $host): ?Tenant
    {
        $host = DomainNormalizer::normalize($host);

        if ($host === '') {
            return null;
        }

        $key = $this->cacheKey($host);
        $cached = $this->cache->get($key);

        if ($cached === self::MISS) {
            return null;
        }

        if (is_int($cached)) {
            return Tenant::query()->find($cached) ?? $this->forgetAndNull($host);
        }

        $tenant = $this->lookup($host);

        $tenant === null
            ? $this->cache->put($key, self::MISS, config('tenancy.cache.negative_ttl'))
            : $this->cache->put($key, $tenant->getKey(), config('tenancy.cache.ttl'));

        return $tenant;
    }

    public function forget(string $host): void
    {
        $host = DomainNormalizer::normalize($host);

        foreach (array_filter([$host, DomainNormalizer::alternateHost($host)]) as $candidate) {
            $this->cache->forget($this->cacheKey($candidate));
        }
    }

    private function lookup(string $host): ?Tenant
    {
        foreach (array_filter([$host, DomainNormalizer::alternateHost($host)]) as $candidate) {
            $tenant = $this->findByDomain($candidate) ?? $this->findBySlug($candidate);

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return null;
    }

    private function findByDomain(string $host): ?Tenant
    {
        $domain = Domain::query()
            ->withoutGlobalScopes()
            ->where('domain', $host)
            ->where('is_verified', true)
            ->whereIn('status', [DomainStatus::Verified, DomainStatus::Active])
            ->first();

        return $domain === null ? null : Tenant::query()->find($domain->tenant_id);
    }

    private function findBySlug(string $host): ?Tenant
    {
        $label = DomainNormalizer::subdomainLabel($host, config('tenancy.platform_domain'));

        if ($label === null || in_array($label, config('tenancy.reserved_subdomains'), true)) {
            return null;
        }

        return Tenant::query()->where('slug', $label)->first();
    }

    private function forgetAndNull(string $host): null
    {
        $this->forget($host);

        return null;
    }

    private function cacheKey(string $host): string
    {
        return config('tenancy.cache.prefix').$host;
    }
}
