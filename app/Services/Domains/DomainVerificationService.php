<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Support\Tenancy\TenantResolver;

/**
 * Proves domain ownership via DNS. A domain is never marked verified unless the
 * ownership TXT record is actually present:
 *
 *   TXT  _shop-verify.{domain}  =  {verification_token}      -> Verified
 *   CNAME {domain} -> cname_target  (or A record in ip list) -> Active (routable)
 *
 * Designed so a Cloudflare API implementation can later replace/augment DnsLookup.
 */
class DomainVerificationService
{
    public function __construct(
        private readonly DnsLookup $dns,
        private readonly TenantResolver $resolver,
    ) {}

    public function verify(Domain $domain): Domain
    {
        if ($domain->type === DomainType::Subdomain) {
            return $domain;
        }

        $host = $domain->domain;
        $domain->last_checked_at = now();

        if (! $this->ownershipProven($domain)) {
            $domain = $this->markUnverified($domain);
            $this->resolver->forget($host);

            return $domain;
        }

        $routed = $this->routedToPlatform($host);

        $domain->forceFill([
            'is_verified' => true,
            'verified_at' => $domain->verified_at ?? now(),
            'status' => $routed ? DomainStatus::Active : DomainStatus::Verified,
            'failure_reason' => $routed ? null : 'Ownership verified, but DNS does not point to the platform yet.',
        ])->save();

        $this->resolver->forget($host);

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    public function instructions(Domain $domain): array
    {
        $host = $domain->domain;
        $isApex = substr_count($host, '.') === 1;
        $target = config('tenancy.custom_domains.cname_target');
        $prefix = config('tenancy.custom_domains.verification_txt_prefix');

        return [
            'ownership' => [
                'type' => 'TXT',
                'name' => "{$prefix}.{$host}",
                'value' => $domain->verification_token,
            ],
            'routing' => $isApex
                ? ['type' => 'CNAME/ALIAS/ANAME', 'name' => '@', 'target' => $target, 'note' => 'Use CNAME flattening (ALIAS/ANAME) at the apex, or add A records provided by support.']
                : ['type' => 'CNAME', 'name' => substr($host, 0, strpos($host, '.')), 'target' => $target],
        ];
    }

    private function ownershipProven(Domain $domain): bool
    {
        if ($domain->verification_token === null) {
            return false;
        }

        $name = config('tenancy.custom_domains.verification_txt_prefix').'.'.$domain->domain;

        foreach ($this->dns->txt($name) as $value) {
            if (hash_equals($domain->verification_token, trim($value, " \t\n\r\0\x0B\""))) {
                return true;
            }
        }

        return false;
    }

    private function routedToPlatform(string $host): bool
    {
        $target = strtolower(config('tenancy.custom_domains.cname_target'));

        if ($this->dns->cname($host) === $target) {
            return true;
        }

        $ips = config('tenancy.custom_domains.ip_addresses');

        return $ips !== [] && array_intersect($this->dns->a($host), $ips) !== [];
    }

    private function markUnverified(Domain $domain): Domain
    {
        $expired = $domain->created_at->lt(now()->subHours(config('tenancy.custom_domains.fail_after_hours')));

        // A previously verified domain that loses its TXT record is demoted, not trusted.
        $domain->forceFill([
            'is_verified' => false,
            'verified_at' => null,
            'is_primary' => false,
            'status' => $expired ? DomainStatus::Failed : DomainStatus::Pending,
            'failure_reason' => 'Ownership TXT record not found.',
        ])->save();

        return $domain;
    }
}
