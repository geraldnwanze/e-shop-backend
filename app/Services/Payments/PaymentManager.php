<?php

namespace App\Services\Payments;

use App\Enums\PaymentProviderType;
use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Resolves a provider implementation + credentials for a tenant. Application
 * code asks for "a provider for this store"; it never names Paystack/Flutterwave.
 */
class PaymentManager
{
    /** @var array<string, Closure(PaymentCredentials): PaymentProvider> */
    private array $custom = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Register/override a provider implementation (used for new gateways and tests).
     *
     * @param  Closure(PaymentCredentials): PaymentProvider  $factory
     */
    public function extend(PaymentProviderType $type, Closure $factory): void
    {
        $this->custom[$type->value] = $factory;
    }

    public function make(PaymentProviderType $type, PaymentCredentials $credentials): PaymentProvider
    {
        if (isset($this->custom[$type->value])) {
            return ($this->custom[$type->value])($credentials);
        }

        return match ($type) {
            PaymentProviderType::Paystack => new PaystackProvider($credentials, config('payments.providers.paystack.base_url')),
            PaymentProviderType::Flutterwave => new FlutterwaveProvider($credentials, config('payments.providers.flutterwave.base_url')),
        };
    }

    /**
     * Provider for the current tenant, or null if the store has not enabled it.
     */
    public function forCurrentTenant(PaymentProviderType $type): ?PaymentProvider
    {
        $credentials = $this->credentialsFor($this->context->requireTenant(), $type);

        return $credentials === null ? null : $this->make($type, $credentials);
    }

    public function forTenant(Tenant $tenant, PaymentProviderType $type): ?PaymentProvider
    {
        return $this->context->runAs($tenant, fn () => $this->forCurrentTenant($type));
    }

    /**
     * Active providers for the current store, default first.
     *
     * @return list<PaymentProviderType>
     */
    public function availableProviders(): array
    {
        $own = PaymentGateway::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get()->pluck('provider')->all();

        $platform = config('payments.use_platform_credentials')
            ? array_values(array_filter(PaymentProviderType::cases(), fn ($t) => $this->platformCredentials($t) !== null && ! in_array($t, $own, true)))
            : [];

        return [...$own, ...$platform];
    }

    private function credentialsFor(Tenant $tenant, PaymentProviderType $type): ?PaymentCredentials
    {
        $gateway = PaymentGateway::query()->where('provider', $type->value)->first();

        if ($gateway !== null) {
            return $gateway->is_active ? new PaymentCredentials($gateway->secret_key, $gateway->webhook_secret, $gateway->public_key) : null;
        }

        return config('payments.use_platform_credentials') ? $this->platformCredentials($type) : null;
    }

    private function platformCredentials(PaymentProviderType $type): ?PaymentCredentials
    {
        $config = config("payments.providers.{$type->value}");

        return filled($config['secret_key'] ?? null)
            ? new PaymentCredentials($config['secret_key'], $config['webhook_secret'] ?? $config['secret_key'])
            : null;
    }
}
