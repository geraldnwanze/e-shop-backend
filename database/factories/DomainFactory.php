<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => fake()->unique()->domainName(),
            'type' => DomainType::Custom,
            'status' => DomainStatus::Verified,
            'is_primary' => false,
            'is_verified' => true,
            'verified_at' => now(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->getKey()]);
    }

    public function subdomainOf(Tenant $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant->getKey(),
            'domain' => $tenant->slug.'.'.config('tenancy.platform_domain'),
            'type' => DomainType::Subdomain,
            'status' => DomainStatus::Active,
            'is_primary' => true,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => DomainStatus::Pending,
            'is_verified' => false,
            'verified_at' => null,
            'verification_token' => bin2hex(random_bytes(16)),
        ]);
    }
}
