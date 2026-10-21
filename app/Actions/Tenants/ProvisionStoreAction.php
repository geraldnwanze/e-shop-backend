<?php

namespace App\Actions\Tenants;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\StoreStatus;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\Theme;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates the tenant, its store and its platform subdomain atomically.
 * The subdomain is resolvable immediately thanks to the wildcard DNS record.
 */
class ProvisionStoreAction
{
    public function __construct(private readonly TenantContext $context, private readonly PlatformSettings $settings, private readonly SubscriptionService $subscriptions) {}

    public function execute(string $storeName, string $slug): Tenant
    {
        return DB::transaction(function () use ($storeName, $slug): Tenant {
            $tenant = Tenant::create(['name' => $storeName, 'slug' => $slug]);

            $this->context->runAs($tenant, function () use ($storeName, $slug): void {
                $store = Store::create(['name' => $storeName, 'slug' => $slug, 'status' => StoreStatus::Active]);
                $theme = Theme::query()->where('slug', $this->settings->get('storefront.default_theme') ?? config('themes.default_theme'))->first();

                if ($theme !== null) {
                    $store->update(['theme_id' => $theme->getKey()]);
                }

                $store->themeConfiguration()->create(['config' => config('themes.default_configuration'), 'theme_id' => $theme?->getKey()]);
                $store->settings()->create(['settings' => config('themes.default_settings')]);

                Domain::create([
                    'domain' => $slug.'.'.config('tenancy.platform_domain'),
                    'type' => DomainType::Subdomain,
                    'status' => DomainStatus::Active,
                    'is_primary' => true,
                    'is_verified' => true,
                    'verified_at' => now(),
                ]);
            });

            $plan = Plan::query()->where('slug', config('plans.default_plan_slug'))->where('is_active', true)->first();

            if ($plan !== null) {
                $this->subscriptions->assign($tenant, $plan);
            }

            return $tenant;
        });
    }
}
