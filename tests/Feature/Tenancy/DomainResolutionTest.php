<?php

use App\Models\Domain;
use App\Models\Store;
use App\Models\Tenant;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

function storefront(string $host)
{
    return test()->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => $host]);
}

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'icecream', 'name' => 'Ice Cream']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'fashion', 'name' => 'Fashion']);
    Store::factory()->forTenant($this->tenantA)->create();
    Store::factory()->forTenant($this->tenantB)->create();
    Domain::factory()->subdomainOf($this->tenantA)->create();
    Domain::factory()->subdomainOf($this->tenantB)->create();
    Domain::factory()->forTenant($this->tenantA)->create(['domain' => 'custom-domain.com']);
    Domain::factory()->forTenant($this->tenantA)->create(['domain' => 'www.icecream.com']);
    Cache::flush();
});

it('resolves platform subdomains to their tenant', function () {
    storefront('icecream.mydomain.test')->assertOk()->assertJsonPath('data.store.name', 'Ice Cream');
    storefront('fashion.mydomain.test')->assertOk()->assertJsonPath('data.store.name', 'Fashion');
});

it('resolves verified custom domains to the owning tenant', function () {
    storefront('custom-domain.com')->assertOk()->assertJsonPath('data.store.slug', 'icecream');
    storefront('www.icecream.com')->assertOk()->assertJsonPath('data.store.slug', 'icecream');
});

it('treats www and apex as equivalent', function () {
    storefront('icecream.com')->assertOk()->assertJsonPath('data.store.slug', 'icecream');
    storefront('www.custom-domain.com')->assertOk()->assertJsonPath('data.store.slug', 'icecream');
});

it('normalises protocol, case, port and trailing dots', function () {
    storefront('HTTPS://ICECREAM.MYDOMAIN.TEST:443/')->assertOk()->assertJsonPath('data.store.slug', 'icecream');
});

it('returns 404 for unknown domains', function () {
    storefront('unknown-domain.com')->assertNotFound()->assertJsonPath('meta.errorCode', 'TENANT_NOT_FOUND');
    storefront('nobody.mydomain.test')->assertNotFound();
});

it('does not resolve unverified custom domains', function () {
    Domain::factory()->forTenant($this->tenantA)->pending()->create(['domain' => 'pending.com']);

    storefront('pending.com')->assertNotFound();
});

it('never resolves reserved platform subdomains', function () {
    storefront('api.mydomain.test')->assertNotFound();
    storefront('app.mydomain.test')->assertNotFound();
});

it('refuses nested subdomains of the platform domain', function () {
    storefront('evil.icecream.mydomain.test')->assertNotFound();
});

it('returns 403 for suspended tenants', function () {
    $this->tenantA->update(['status' => 'suspended']);

    storefront('icecream.mydomain.test')->assertForbidden()->assertJsonPath('meta.errorCode', 'TENANT_SUSPENDED');
});

it('falls back to the request host when no storefront header is sent', function () {
    $this->getJson('http://fashion.mydomain.test/api/v1/storefront/store')
        ->assertOk()->assertJsonPath('data.store.slug', 'fashion');
});

it('caches resolution per hostname without crossing tenants', function () {
    $resolver = app(TenantResolver::class);

    expect($resolver->resolve('icecream.mydomain.test')->id)->toBe($this->tenantA->id)
        ->and($resolver->resolve('fashion.mydomain.test')->id)->toBe($this->tenantB->id);

    // served from cache, still correct per host
    expect($resolver->resolve('icecream.mydomain.test')->id)->toBe($this->tenantA->id)
        ->and(Cache::get('tenancy:host:fashion.mydomain.test'))->toBe($this->tenantB->id);
});

it('forgets cached resolution when a domain is released', function () {
    $resolver = app(TenantResolver::class);
    expect($resolver->resolve('custom-domain.com'))->not->toBeNull();

    Domain::withoutTenantScope()->where('domain', 'custom-domain.com')->delete();
    $resolver->forget('custom-domain.com');

    expect($resolver->resolve('custom-domain.com'))->toBeNull();
});

it('stores domains normalised and unique', function () {
    $domain = Domain::factory()->forTenant($this->tenantB)->create(['domain' => 'HTTPS://Shop.Example.COM/']);

    expect($domain->domain)->toBe('shop.example.com');

    Domain::factory()->forTenant($this->tenantA)->create(['domain' => 'shop.example.com']);
})->throws(QueryException::class);
