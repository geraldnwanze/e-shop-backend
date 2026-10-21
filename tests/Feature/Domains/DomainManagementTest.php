<?php

use App\Enums\DomainStatus;
use App\Jobs\VerifyDomainJob;
use App\Models\Domain;
use App\Services\Domains\DnsLookup;
use App\Services\Domains\DomainVerificationService;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class FakeDns implements DnsLookup
{
    public static array $txt = [];

    public static array $cname = [];

    public static array $a = [];

    public function txt(string $host): array
    {
        return self::$txt[$host] ?? [];
    }

    public function cname(string $host): ?string
    {
        return self::$cname[$host] ?? null;
    }

    public function a(string $host): array
    {
        return self::$a[$host] ?? [];
    }
}

beforeEach(function () {
    FakeDns::$txt = FakeDns::$cname = FakeDns::$a = [];
    $this->app->bind(DnsLookup::class, FakeDns::class);
    config(['tenancy.custom_domains.cname_target' => 'shops.mydomain.test']);
    Cache::flush();

    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    Domain::factory()->subdomainOf($this->a['tenant'])->create();
    $this->actingAs($this->a['owner'], 'sanctum');
});

it('adds a pending custom domain with DNS instructions', function () {
    $response = $this->postJson('/api/v1/domains', ['domain' => 'https://WWW.IceCream.com/'])
        ->assertCreated()
        ->assertJsonPath('data.domain', 'www.icecream.com')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.isVerified', false)
        ->assertJsonPath('data.dnsInstructions.routing.type', 'CNAME')
        ->assertJsonPath('data.dnsInstructions.routing.name', 'www')
        ->assertJsonPath('data.dnsInstructions.routing.target', 'shops.mydomain.test')
        ->assertJsonPath('data.dnsInstructions.ownership.name', '_shop-verify.www.icecream.com');

    expect($response->json('data.dnsInstructions.ownership.value'))->toHaveLength(40);
});

it('rejects invalid, platform and duplicate domains', function (string $domain) {
    $this->postJson('/api/v1/domains', ['domain' => $domain])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['domain']]]);
})->with(['not a domain', 'localhost', '192.168.0.1', 'evil.mydomain.test', 'mydomain.test']);

it('rejects domains already claimed by any tenant without revealing the owner', function () {
    Domain::factory()->forTenant($this->b['tenant'])->create(['domain' => 'taken.com']);

    $this->postJson('/api/v1/domains', ['domain' => 'TAKEN.com'])
        ->assertUnprocessable()->assertJsonPath('meta.errors.domain.0', 'This domain is already in use.');
});

it('enforces the per-tenant custom domain limit', function () {
    config(['tenancy.custom_domains.max_per_tenant' => 1]);

    $this->postJson('/api/v1/domains', ['domain' => 'one.com'])->assertCreated();
    $this->postJson('/api/v1/domains', ['domain' => 'two.com'])->assertStatus(409);
});

it('does not verify a domain until the ownership TXT record exists', function () {
    $id = $this->postJson('/api/v1/domains', ['domain' => 'icecream.com'])->json('data.id');

    $this->postJson("/api/v1/domains/{$id}/verify")
        ->assertOk()->assertJsonPath('data.isVerified', false)->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.failureReason', 'Ownership TXT record not found.');

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'icecream.com'])->assertNotFound();
});

it('marks a domain verified (not active) when only ownership is proven', function () {
    $created = $this->postJson('/api/v1/domains', ['domain' => 'icecream.com'])->json('data');
    FakeDns::$txt['_shop-verify.icecream.com'] = [$created['dnsInstructions']['ownership']['value']];

    $this->postJson("/api/v1/domains/{$created['id']}/verify")
        ->assertOk()->assertJsonPath('data.isVerified', true)->assertJsonPath('data.status', 'verified');
});

it('activates a domain once ownership and routing are both proven, and then resolves it', function () {
    $created = $this->postJson('/api/v1/domains', ['domain' => 'www.icecream.com'])->json('data');
    FakeDns::$txt['_shop-verify.www.icecream.com'] = ['"'.$created['dnsInstructions']['ownership']['value'].'"'];
    FakeDns::$cname['www.icecream.com'] = 'shops.mydomain.test';

    $this->postJson("/api/v1/domains/{$created['id']}/verify")
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.failureReason', null);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'www.icecream.com'])
        ->assertOk()->assertJsonPath('data.store.slug', 'icecream');
});

it('rejects a TXT value that belongs to someone else', function () {
    $created = $this->postJson('/api/v1/domains', ['domain' => 'icecream.com'])->json('data');
    FakeDns::$txt['_shop-verify.icecream.com'] = [str_repeat('a', 40)];

    $this->postJson("/api/v1/domains/{$created['id']}/verify")->assertOk()->assertJsonPath('data.isVerified', false);
});

it('marks long-unverified domains as failed', function () {
    $created = $this->postJson('/api/v1/domains', ['domain' => 'icecream.com'])->json('data');
    Domain::withoutTenantScope()->where('id', $created['id'])->update(['created_at' => now()->subDays(5)]);

    $this->postJson("/api/v1/domains/{$created['id']}/verify")->assertJsonPath('data.status', 'failed');
});

it('demotes a verified domain whose ownership record disappears', function () {
    $domain = Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'icecream.com', 'verification_token' => 'tok', 'status' => DomainStatus::Verified, 'is_primary' => true]);

    $this->postJson("/api/v1/domains/{$domain->id}/verify")->assertOk()->assertJsonPath('data.isVerified', false);

    expect($domain->fresh()->is_primary)->toBeFalse();
});

it('invalidates cached resolution on verify', function () {
    $resolver = app(TenantResolver::class);
    expect($resolver->resolve('icecream.com'))->toBeNull(); // negative-cached

    $created = $this->postJson('/api/v1/domains', ['domain' => 'icecream.com'])->json('data');
    FakeDns::$txt['_shop-verify.icecream.com'] = [$created['dnsInstructions']['ownership']['value']];
    $this->postJson("/api/v1/domains/{$created['id']}/verify")->assertOk();

    expect($resolver->resolve('icecream.com')?->id)->toBe($this->a['tenant']->id);
});

it('switches the primary domain and requires verification', function () {
    $pending = Domain::factory()->forTenant($this->a['tenant'])->pending()->create(['domain' => 'pending.com']);
    $verified = Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'verified.com']);

    $this->postJson("/api/v1/domains/{$pending->id}/primary")->assertStatus(409);
    $this->postJson("/api/v1/domains/{$verified->id}/primary")->assertOk()->assertJsonPath('data.isPrimary', true);

    expect(Domain::withoutTenantScope()->where('is_primary', true)->pluck('domain')->all())->toBe(['verified.com']);
});

it('removes custom domains, restores the subdomain as primary and forgets the cache', function () {
    $custom = Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'verified.com', 'is_primary' => true]);
    Domain::withoutTenantScope()->where('type', 'SUBDOMAIN')->update(['is_primary' => false]);
    expect(app(TenantResolver::class)->resolve('verified.com'))->not->toBeNull();

    $this->deleteJson("/api/v1/domains/{$custom->id}")->assertOk();

    expect(Domain::withoutTenantScope()->where('is_primary', true)->value('domain'))->toBe('icecream.mydomain.test')
        ->and(app(TenantResolver::class)->resolve('verified.com'))->toBeNull();
});

it('does not allow removing the platform subdomain', function () {
    $sub = Domain::withoutTenantScope()->where('type', 'SUBDOMAIN')->firstOrFail();

    $this->deleteJson("/api/v1/domains/{$sub->id}")->assertStatus(409);
});

it('hides other tenants\' domains and blocks cross-tenant actions', function () {
    $theirs = Domain::factory()->forTenant($this->b['tenant'])->create(['domain' => 'theirs.com']);

    $this->getJson('/api/v1/domains')->assertJsonMissing(['domain' => 'theirs.com']);
    $this->getJson("/api/v1/domains/{$theirs->id}")->assertNotFound();
    $this->postJson("/api/v1/domains/{$theirs->id}/verify")->assertNotFound();
    $this->postJson("/api/v1/domains/{$theirs->id}/primary")->assertNotFound();
    $this->deleteJson("/api/v1/domains/{$theirs->id}")->assertNotFound();
    expect(Domain::withoutTenantScope()->where('domain', 'theirs.com')->exists())->toBeTrue();
});

it('queues verification for pending domains via the scheduled command', function () {
    Queue::fake();
    Domain::factory()->forTenant($this->a['tenant'])->pending()->create(['domain' => 'p1.com']);
    Domain::factory()->forTenant($this->b['tenant'])->pending()->create(['domain' => 'p2.com']);
    Domain::factory()->forTenant($this->b['tenant'])->create(['domain' => 'done.com', 'status' => DomainStatus::Active]);

    $this->artisan('domains:verify-pending')->assertSuccessful();

    Queue::assertPushed(VerifyDomainJob::class, 2);
});

it('verifies across tenants inside the queued job', function () {
    $domain = Domain::factory()->forTenant($this->b['tenant'])->create(['domain' => 'p2.com', 'status' => DomainStatus::Pending, 'is_verified' => false, 'verification_token' => 'tok123']);
    FakeDns::$txt['_shop-verify.p2.com'] = ['tok123'];

    (new VerifyDomainJob($domain->id))->handle(app(DomainVerificationService::class));

    expect(Domain::withoutTenantScope()->find($domain->id)->is_verified)->toBeTrue();
});
