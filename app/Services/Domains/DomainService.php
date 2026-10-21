<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Exceptions\BaseApiException;
use App\Models\Domain;
use App\Services\Platform\PlanLimits;
use App\Support\Tenancy\DomainNormalizer;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DomainService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly PlanLimits $limits,
    ) {}

    public function addCustomDomain(string $input): Domain
    {
        $host = DomainNormalizer::normalize($input);

        if (! DomainNormalizer::isValid($host)) {
            throw ValidationException::withMessages(['domain' => ['Enter a valid domain such as shop.example.com.']]);
        }

        $platform = strtolower(config('tenancy.platform_domain'));
        if ($host === $platform || str_ends_with($host, '.'.$platform)) {
            throw ValidationException::withMessages(['domain' => ['Platform domains cannot be added as custom domains.']]);
        }

        if (Domain::withoutTenantScope()->where('domain', $host)->exists()) {
            throw ValidationException::withMessages(['domain' => ['This domain is already in use.']]);
        }

        $this->limits->assertCanAdd($this->context->requireTenant(), 'custom_domains', Domain::query()->where('type', DomainType::Custom)->count(), 'custom domains');

        return Domain::create([
            'domain' => $host,
            'type' => DomainType::Custom,
            'status' => DomainStatus::Pending,
            'is_verified' => false,
            'verification_token' => bin2hex(random_bytes(20)),
        ]);
    }

    public function makePrimary(Domain $domain): Domain
    {
        if (! $domain->is_verified) {
            throw BaseApiException::conflict('Only verified domains can be primary.');
        }

        DB::transaction(function () use ($domain): void {
            Domain::query()->where('is_primary', true)->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);
        });

        return $domain->refresh();
    }

    public function remove(Domain $domain): void
    {
        if ($domain->type === DomainType::Subdomain) {
            throw BaseApiException::conflict('The platform subdomain cannot be removed.');
        }

        $host = $domain->domain;

        DB::transaction(function () use ($domain): void {
            $wasPrimary = $domain->is_primary;
            $domain->delete();

            if ($wasPrimary) {
                Domain::query()->where('type', DomainType::Subdomain)->update(['is_primary' => true]);
            }
        });

        $this->resolver->forget($host);
    }
}
