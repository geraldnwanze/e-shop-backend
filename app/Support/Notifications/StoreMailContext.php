<?php

namespace App\Support\Notifications;

use App\Models\Store;
use App\Models\ThemeConfiguration;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;

/**
 * Branding and links shared by every email a store sends. Read inside a tenant context.
 */
final class StoreMailContext
{
    /**
     * @return array{store: string, accent: string, trackUrl: string, storeUrl: string, supportEmail: string|null, dashboardUrl: string}
     */
    public static function current(): array
    {
        $tenant = app(TenantContext::class)->requireTenant();
        $primary = data_get(ThemeConfiguration::query()->first()?->config, 'colors.primary');

        return [
            'store' => Store::query()->value('name') ?? $tenant->name,
            'accent' => is_string($primary) && preg_match('/^#[0-9a-fA-F]{6}$/', $primary) ? $primary : '#5b3df5',
            'trackUrl' => StorefrontUrl::for($tenant, '/orders/track'),
            'storeUrl' => StorefrontUrl::for($tenant, '/'),
            'supportEmail' => NotificationPreferences::forCurrentTenant()->supportEmail(),
            'dashboardUrl' => rtrim(config('tenancy.frontend_url'), '/').'/dashboard',
        ];
    }

    /**
     * Money in minor units, formatted for an email, e.g. "1,500.00 NGN".
     */
    public static function money(int $minor, string $currency): string
    {
        return number_format($minor / 100, 2).' '.$currency;
    }
}
