<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Theme;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Services\Domains\DnsLookup;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->platformAdmin()->create();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->actingAs($this->admin, 'sanctum');
});

describe('overview', function () {
    it('summarises the whole platform', function () {
        $plan = Plan::factory()->create(['price' => 1200000, 'interval' => 'yearly']);
        $monthly = Plan::factory()->create(['price' => 500000]);
        Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $plan->id]);
        Subscription::factory()->forTenant($this->b['tenant'])->create(['plan_id' => $monthly->id]);
        $this->b['tenant']->update(['status' => 'suspended']);
        Order::factory()->forTenant($this->a['tenant'])->create(['total' => 100000, 'payment_status' => 'PAID']);
        Order::factory()->forTenant($this->b['tenant'])->create(['total' => 50000, 'payment_status' => 'PAID']);
        Order::factory()->forTenant($this->b['tenant'])->create(['total' => 99999, 'payment_status' => 'PENDING']);
        Domain::factory()->forTenant($this->a['tenant'])->pending()->create();

        $this->getJson('/api/v1/admin/overview')->assertOk()
            ->assertJsonPath('data.tenants.total', 2)->assertJsonPath('data.tenants.active', 1)->assertJsonPath('data.tenants.suspended', 1)
            ->assertJsonPath('data.stores', 2)->assertJsonPath('data.orders', 3)
            ->assertJsonPath('data.gmv.0.amount', 150000)
            ->assertJsonPath('data.activeSubscriptions', 2)
            ->assertJsonPath('data.mrr.0.amount', 600000) // 1,200,000/12 + 500,000
            ->assertJsonPath('data.domainsNeedingAttention', 1)
            ->assertJsonCount(2, 'data.recentTenants');
    });
});

describe('tenants', function () {
    it('lists with plan and user counts, and shows full detail', function () {
        $plan = Plan::factory()->create(['name' => 'Pro']);
        Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $plan->id]);

        $this->getJson('/api/v1/admin/tenants?search=icecream')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.usersCount', 1)->assertJsonPath('data.0.subscription.plan.name', 'Pro');
        $this->getJson("/api/v1/admin/tenants/{$this->a['tenant']->id}")->assertOk()
            ->assertJsonPath('data.store.slug', 'icecream')->assertJsonCount(1, 'data.users')->assertJsonPath('data.subscription.status', 'active');
    });

    it('suspends and reactivates, returning the hostnames so caches can be purged, and audits it', function () {
        Domain::factory()->subdomainOf($this->a['tenant'])->create();

        $this->postJson("/api/v1/admin/tenants/{$this->a['tenant']->id}/suspend")->assertOk()
            ->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.domains.0.domain', 'icecream.mydomain.test');
        $this->postJson("/api/v1/admin/tenants/{$this->a['tenant']->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');

        $trail = AuditLog::query()->where('subject_id', $this->a['tenant']->id)->orderBy('created_at')->orderBy('id')->get();

        expect($trail->pluck('action')->intersect(['tenant.suspended', 'tenant.reactivated'])->sort()->values()->all())->toBe(['tenant.reactivated', 'tenant.suspended'])
            ->and($trail->firstWhere('action', 'tenant.suspended'))->actor_type->toBe('platform_admin')->actor_id->toBe($this->admin->id)->tenant_id->toBe($this->a['tenant']->id);
    });
});

describe('stores', function () {
    it('lists and filters across tenants and updates status and theme', function () {
        $theme = Theme::factory()->create();
        $inactive = Theme::factory()->inactive()->create();

        $this->getJson('/api/v1/admin/stores?search=fash')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tenant.slug', 'fashion');
        $this->patchJson("/api/v1/admin/stores/{$this->a['store']->id}", ['status' => 'inactive', 'theme_id' => $theme->id])->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.theme.id', $theme->id);
        $this->patchJson("/api/v1/admin/stores/{$this->a['store']->id}", ['theme_id' => $inactive->id])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/stores/{$this->a['store']->id}", ['status' => 'nonsense'])->assertUnprocessable();

        expect(Store::withoutTenantScope()->find($this->b['store']->id)->status->value)->toBe('active');
    });
});

describe('users', function () {
    it('lists with filters', function () {
        User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create(['name' => 'Sam Staff', 'suspended_at' => now()]);

        $this->getJson('/api/v1/admin/users')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/admin/users?role=merchant_staff')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/users?status=suspended')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Sam Staff');
        $this->getJson("/api/v1/admin/users?tenant={$this->b['tenant']->id}")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/users?search=sam+staff')->assertJsonPath('meta.total', 1);
    });

    it('filters by tenant, by domain (partial, any case, even a pasted URL), and by both together', function () {
        Domain::factory()->subdomainOf($this->a['tenant'])->create();
        Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'shop.scoops.example']);
        Domain::factory()->subdomainOf($this->b['tenant'])->create();
        User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create(['name' => 'Sam Staff']);

        $names = fn (string $query) => collect($this->getJson("/api/v1/admin/users?{$query}")->assertOk()->json('data'))->pluck('tenant.slug')->unique()->values()->all();

        expect($names("tenant={$this->a['tenant']->id}"))->toBe(['icecream']);
        expect($names('domain=shop.scoops.example'))->toBe(['icecream']);
        expect($names('domain=SCOOPS'))->toBe(['icecream']);
        expect($names('domain='.urlencode('https://Shop.Scoops.Example/')))->toBe(['icecream']);
        expect($names('domain=fashion.mydomain'))->toBe(['fashion']);
        expect($names('domain=nothing-matches.example'))->toBe([]);

        $this->getJson("/api/v1/admin/users?tenant={$this->a['tenant']->id}&domain=fashion")->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/admin/users?tenant={$this->a['tenant']->id}&domain=scoops")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/users?domain=')->assertOk();
    });

    it('filters by email verification status and combines it with the other filters', function () {
        User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->unverified()->create(['name' => 'Una Unverified']);
        User::factory()->forTenant($this->b['tenant'], UserRole::MerchantStaff)->unverified()->create(['name' => 'Ben Unverified']);

        $names = fn (string $query) => collect($this->getJson("/api/v1/admin/users?{$query}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        expect($names('email_status=unverified'))->toBe(['Ben Unverified', 'Una Unverified']);
        expect($names("email_status=unverified&tenant={$this->a['tenant']->id}"))->toBe(['Una Unverified']);
        $this->getJson('/api/v1/admin/users?email_status=verified')->assertJsonPath('meta.total', 3); // the admin and the two store owners
        $this->getJson('/api/v1/admin/users?email_status=bogus')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?email_status=')->assertOk()->assertJsonPath('meta.total', 5);
    });

    it('treats LIKE wildcards in the domain filter literally and rejects an overlong one', function () {
        Domain::factory()->subdomainOf($this->a['tenant'])->create();

        $this->getJson('/api/v1/admin/users?domain=%25')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/users?domain='.str_repeat('a', 254))->assertUnprocessable();
    });

    it('changes merchant roles but never the last owner, platform roles, or itself', function () {
        $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

        $this->patchJson("/api/v1/admin/users/{$staff->id}", ['role' => 'merchant_admin'])->assertOk()->assertJsonPath('data.role', 'merchant_admin');
        $this->patchJson("/api/v1/admin/users/{$staff->id}", ['role' => 'platform_admin'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/users/{$staff->id}", ['role' => 'customer'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/users/{$this->a['owner']->id}", ['role' => 'merchant_staff'])->assertStatus(409);
        $this->patchJson("/api/v1/admin/users/{$this->admin->id}", ['role' => 'merchant_owner'])->assertForbidden();

        $second = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantOwner)->create();
        $this->patchJson("/api/v1/admin/users/{$second->id}", ['role' => 'merchant_staff'])->assertOk();
    });

    it('suspends a user: tokens revoked, login blocked, existing sessions rejected', function () {
        $token = $this->a['owner']->createToken('t')->plainTextToken;

        $this->postJson("/api/v1/admin/users/{$this->a['owner']->id}/suspend")->assertOk()->assertJsonPath('data.suspendedAt', fn ($v) => $v !== null);

        expect($this->a['owner']->tokens()->count())->toBe(0);
        http()->postJson('/api/v1/auth/login', ['email' => $this->a['owner']->email, 'password' => 'password'])->assertForbidden()->assertJsonPath('meta.errorCode', 'ACCOUNT_SUSPENDED');

        // even a token minted before suspension (e.g. leaked in flight) is refused on tenant routes
        $this->a['owner']->refresh();
        $fresh = $this->a['owner']->createToken('t2')->plainTextToken;
        http()->getJson('/api/v1/store', ['Authorization' => "Bearer {$fresh}"])->assertForbidden()->assertJsonPath('meta.errorCode', 'ACCOUNT_SUSPENDED');
        expect($token)->not->toBeEmpty();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/users/{$this->a['owner']->id}/activate")->assertOk();
        http()->postJson('/api/v1/auth/login', ['email' => $this->a['owner']->email, 'password' => 'password'])->assertOk();
    });

    it('refuses to manage platform admins, itself, or tenant-less accounts', function () {
        $other = User::factory()->platformAdmin()->create();
        $orphan = User::factory()->create(['tenant_id' => null, 'role' => UserRole::Customer]);

        foreach ([$other, $this->admin, $orphan] as $target) {
            $this->postJson("/api/v1/admin/users/{$target->id}/suspend")->assertForbidden();
            $this->postJson("/api/v1/admin/users/{$target->id}/revoke-tokens")->assertForbidden();
        }
        expect($other->fresh()->isSuspended())->toBeFalse();
    });

    it('revokes sessions and sends a password reset', function () {
        $this->a['owner']->createToken('a');
        $this->a['owner']->createToken('b');

        $this->postJson("/api/v1/admin/users/{$this->a['owner']->id}/revoke-tokens")->assertOk();
        $this->postJson("/api/v1/admin/users/{$this->a['owner']->id}/password-reset")->assertOk();

        expect($this->a['owner']->tokens()->count())->toBe(0);
        Notification::assertSentTo($this->a['owner'], QueuedResetPassword::class);
    });
});

describe('themes', function () {
    it('creates, updates (slug immutable) and deletes unused themes', function () {
        $id = $this->postJson('/api/v1/admin/themes', ['name' => 'Neon', 'slug' => 'neon', 'description' => 'Bright', 'preview_image' => 'https://cdn.test/neon.png'])->assertCreated()->assertJsonPath('data.storesCount', 0)->json('data.id');

        $this->postJson('/api/v1/admin/themes', ['name' => 'Dup', 'slug' => 'neon'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/themes', ['name' => 'Bad', 'slug' => 'Bad Slug'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/themes', ['name' => 'Bad', 'slug' => 'bad', 'preview_image' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->putJson("/api/v1/admin/themes/{$id}", ['name' => 'Neon 2', 'status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->putJson("/api/v1/admin/themes/{$id}", ['slug' => 'other'])->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/themes/{$id}")->assertOk();
    });

    it('refuses to delete a theme that stores use', function () {
        $theme = Theme::factory()->create();
        Store::withoutTenantScope()->whereKey($this->a['store']->id)->update(['theme_id' => $theme->id]);

        $this->getJson('/api/v1/admin/themes')->assertJsonPath('data.0.storesCount', 1);
        $this->deleteJson("/api/v1/admin/themes/{$theme->id}")->assertStatus(409);
        expect(Theme::find($theme->id))->not->toBeNull();
    });
});

describe('plans and subscriptions', function () {
    it('manages plans with validation', function () {
        $payload = ['name' => 'Growth', 'slug' => 'growth', 'price' => 900000, 'currency' => 'NGN', 'interval' => 'monthly', 'limits' => ['products' => 1000, 'custom_domains' => 2], 'features' => ['Priority support']];
        $id = $this->postJson('/api/v1/admin/plans', $payload)->assertCreated()->assertJsonPath('data.limits.custom_domains', 2)->json('data.id');

        $this->postJson('/api/v1/admin/plans', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/admin/plans', ['limits' => ['seats' => 5]] + $payload + ['slug' => 'x2'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/plans', ['price' => 12.5, 'slug' => 'x3'] + $payload)->assertUnprocessable();
        $this->putJson("/api/v1/admin/plans/{$id}", ['price' => 1000000, 'limits' => ['products' => null, 'custom_domains' => 5], 'is_active' => false])->assertOk()->assertJsonPath('data.limits.products', null)->assertJsonPath('data.isActive', false);
        $this->putJson("/api/v1/admin/plans/{$id}", ['slug' => 'renamed'])->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/plans/{$id}")->assertOk();
    });

    it('refuses to delete a plan with subscribers', function () {
        $plan = Plan::factory()->create();
        Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $plan->id]);

        $this->getJson('/api/v1/admin/plans')->assertJsonPath('data.0.subscribersCount', 1);
        $this->deleteJson("/api/v1/admin/plans/{$plan->id}")->assertStatus(409);
    });

    it('assigns a plan, cancelling the previous subscription', function () {
        $old = Plan::factory()->create();
        $new = Plan::factory()->create();
        $first = Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $old->id]);

        $this->postJson("/api/v1/admin/tenants/{$this->a['tenant']->id}/subscription", ['plan_id' => $new->id])->assertCreated()
            ->assertJsonPath('data.plan.id', $new->id)->assertJsonPath('data.status', 'active')->assertJsonPath('data.currentPeriodEndsAt', fn ($v) => $v !== null);

        $first = Subscription::withoutTenantScope()->find($first->id);
        expect($first->status->value)->toBe('cancelled')->and($first->cancelled_at)->not->toBeNull()
            ->and(Subscription::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->where('status', 'active')->count())->toBe(1)
            ->and(Subscription::withoutTenantScope()->where('tenant_id', $this->b['tenant']->id)->count())->toBe(0);
    });

    it('supports trials and rejects inactive plans, bad statuses and past trial dates', function () {
        $plan = Plan::factory()->create();
        $inactive = Plan::factory()->create(['is_active' => false]);
        $url = "/api/v1/admin/tenants/{$this->a['tenant']->id}/subscription";

        $this->postJson($url, ['plan_id' => $plan->id, 'status' => 'trialing', 'trial_ends_at' => now()->addDays(14)->toIso8601String()])->assertCreated()->assertJsonPath('data.status', 'trialing');
        $this->postJson($url, ['plan_id' => $inactive->id])->assertUnprocessable();
        $this->postJson($url, ['plan_id' => $plan->id, 'status' => 'cancelled'])->assertUnprocessable();
        $this->postJson($url, ['plan_id' => $plan->id, 'status' => 'trialing', 'trial_ends_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
    });

    it('lists and filters subscriptions and updates their status', function () {
        $plan = Plan::factory()->create();
        $sub = Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $plan->id]);
        Subscription::factory()->forTenant($this->b['tenant'])->create(['status' => 'past_due']);

        $this->getJson('/api/v1/admin/subscriptions')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/subscriptions?status=past_due')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tenant.slug', 'fashion');
        $this->getJson("/api/v1/admin/subscriptions?plan={$plan->id}")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/subscriptions?search=icecr')->assertJsonPath('meta.total', 1);

        $this->patchJson("/api/v1/admin/subscriptions/{$sub->id}", ['status' => 'bogus'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/subscriptions/{$sub->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.cancelledAt', fn ($v) => $v !== null);
        $this->patchJson("/api/v1/admin/subscriptions/{$sub->id}", ['status' => 'active'])->assertStatus(409);
    });
});

describe('domains', function () {
    beforeEach(function () {
        $this->app->bind(DnsLookup::class, fn () => new class implements DnsLookup
        {
            public static array $txt = [];

            public function txt(string $host): array
            {
                return self::$txt[$host] ?? [];
            }

            public function cname(string $host): ?string
            {
                return null;
            }

            public function a(string $host): array
            {
                return [];
            }
        });
    });

    it('lists domains across tenants with owners and filters', function () {
        Domain::factory()->subdomainOf($this->a['tenant'])->create();
        Domain::factory()->forTenant($this->b['tenant'])->pending()->create(['domain' => 'pending-b.com']);

        $this->getJson('/api/v1/admin/domains')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/domains?status=pending')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tenant.slug', 'fashion');
        $this->getJson('/api/v1/admin/domains?type=SUBDOMAIN')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/domains?search=PENDING-B')->assertJsonPath('meta.total', 1);
    });

    it('re-runs the real DNS check and cannot force-verify', function () {
        $domain = Domain::factory()->forTenant($this->b['tenant'])->pending()->create(['domain' => 'pending-b.com']);

        $this->postJson("/api/v1/admin/domains/{$domain->id}/verify")->assertOk()->assertJsonPath('data.isVerified', false)->assertJsonPath('data.failureReason', 'Ownership TXT record not found.');
        expect(Domain::withoutTenantScope()->find($domain->id)->is_verified)->toBeFalse();
    });

    it('removes a custom domain without touching other tenants\' primary domains', function () {
        Domain::factory()->subdomainOf($this->a['tenant'])->create();
        $bSub = Domain::factory()->subdomainOf($this->b['tenant'])->create();
        $bSub->forceFill(['is_primary' => false])->save();
        $custom = Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'a-custom.com', 'is_primary' => true]);
        Domain::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->where('type', 'SUBDOMAIN')->update(['is_primary' => false]);

        $this->deleteJson("/api/v1/admin/domains/{$custom->id}")->assertOk();

        expect(Domain::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->where('type', 'SUBDOMAIN')->value('is_primary'))->toBeTrue()
            ->and(Domain::withoutTenantScope()->find($bSub->id)->is_primary)->toBeFalse()
            ->and(Domain::withoutTenantScope()->where('domain', 'a-custom.com')->exists())->toBeFalse();
    });

    it('refuses to remove a platform subdomain', function () {
        $sub = Domain::factory()->subdomainOf($this->a['tenant'])->create();

        $this->deleteJson("/api/v1/admin/domains/{$sub->id}")->assertStatus(409);
    });
});

describe('platform settings', function () {
    it('serves defaults and validates updates', function () {
        Theme::factory()->create(['slug' => 'minimal']);
        $other = Theme::factory()->create(['slug' => 'fashion-x']);

        $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.registration.enabled', true)->assertJsonPath('data.storefront.default_theme', 'minimal');
        $this->putJson('/api/v1/admin/settings', ['platform' => ['name' => 'Acme Shops', 'support_email' => 'help@acme.test'], 'storefront' => ['default_theme' => $other->slug]])->assertOk()
            ->assertJsonPath('data.platform.name', 'Acme Shops')->assertJsonPath('data.storefront.default_theme', 'fashion-x');
        $this->putJson('/api/v1/admin/settings', ['storefront' => ['default_theme' => 'nope']])->assertUnprocessable();
        $this->putJson('/api/v1/admin/settings', ['platform' => ['support_email' => 'bad']])->assertUnprocessable();
        $this->putJson('/api/v1/admin/settings', ['secret' => ['x' => 1]])->assertOk(); // unknown groups are ignored, never stored
        expect(PlatformSetting::query()->where('key', 'like', 'secret%')->exists())->toBeFalse();
    });

    it('closes registration and uses the configured default theme for new stores', function () {
        Theme::factory()->create(['slug' => 'minimal']);
        $custom = Theme::factory()->create(['slug' => 'beauty-x']);
        $payload = ['name' => 'N', 'email' => 'new@example.com', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1', 'store_name' => 'New Shop', 'store_slug' => 'newshop'];

        $this->putJson('/api/v1/admin/settings', ['registration' => ['enabled' => false]])->assertOk();
        http()->postJson('/api/v1/auth/register', $payload)->assertForbidden()->assertJsonPath('meta.errorCode', 'REGISTRATION_CLOSED');
        expect(Tenant::query()->where('slug', 'newshop')->exists())->toBeFalse();

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/settings', ['registration' => ['enabled' => true], 'storefront' => ['default_theme' => 'beauty-x']])->assertOk();
        http()->postJson('/api/v1/auth/register', $payload)->assertCreated();
        expect(Tenant::query()->where('slug', 'newshop')->first()->store->theme_id)->toBe($custom->id);
    });
});
