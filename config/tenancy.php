<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Domain
    |--------------------------------------------------------------------------
    |
    | The root domain the platform runs on. Every store receives a subdomain of
    | this domain (icecream.mydomain.com). A wildcard DNS record (*.domain)
    | means no per-store DNS provisioning is ever needed.
    |
    */

    'platform_domain' => env('PLATFORM_DOMAIN', 'mydomain.test'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | Subdomains that can never be claimed as a store slug / resolved as a tenant.
    |
    */

    'reserved_subdomains' => [
        'www', 'app', 'api', 'admin', 'shops', 'mail', 'smtp', 'ftp', 'cdn', 'static',
        'assets', 'media', 'status', 'support', 'help', 'docs', 'blog', 'dashboard',
        'platform', 'billing', 'staging', 'dev', 'test',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storefront Host Header
    |--------------------------------------------------------------------------
    |
    | The Next.js storefront calls the API from its server, so the request host
    | is the API host. The storefront forwards the visitor's hostname in this
    | header; the tenant resolver trusts it ONLY on public storefront routes.
    | Authenticated merchant routes always derive the tenant from the user.
    |
    */

    'storefront_host_header' => 'X-Storefront-Host',

    /*
    |--------------------------------------------------------------------------
    | Platform Admin Acting-As Header
    |--------------------------------------------------------------------------
    |
    | Platform administrators may operate inside a tenant by sending the tenant
    | UUID in this header. It is ignored for every other role.
    |
    */

    'acting_tenant_header' => 'X-Acting-Tenant',

    /*
    |--------------------------------------------------------------------------
    | Resolution Cache
    |--------------------------------------------------------------------------
    |
    | Hostname -> tenant id lookups are cached. Keys are namespaced by hostname,
    | so one tenant's entry can never be served for another host. Misses are
    | cached briefly to protect the database from random-host scans.
    |
    */

    'cache' => [
        'ttl' => (int) env('TENANCY_CACHE_TTL', 300),
        'negative_ttl' => (int) env('TENANCY_CACHE_NEGATIVE_TTL', 30),
        'prefix' => 'tenancy:host:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Domains
    |--------------------------------------------------------------------------
    */

    'custom_domains' => [
        'cname_target' => env('CUSTOM_DOMAIN_CNAME_TARGET', 'shops.'.env('PLATFORM_DOMAIN', 'mydomain.test')),
        'verification_txt_prefix' => '_shop-verify',
        'ip_addresses' => array_filter(explode(',', (string) env('CUSTOM_DOMAIN_IPS', ''))),
        'max_per_tenant' => (int) env('CUSTOM_DOMAIN_MAX', 3),
        'fail_after_hours' => 72,
    ],

    'storefront_scheme' => env('STOREFRONT_SCHEME', 'https'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    | A store cannot take orders until its owner has confirmed their email address. This is
    | the check that stops a typo'd address or a throwaway sign-up from selling. Turn it off
    | only for local development without a mail server.
    */
    'require_verified_email_to_sell' => (bool) env('REQUIRE_VERIFIED_EMAIL_TO_SELL', true),
];
