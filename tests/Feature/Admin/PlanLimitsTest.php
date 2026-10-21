<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->m = createMerchant('icecream');
});

function subscribe(Tenant $tenant, array $limits, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
{
    return Subscription::factory()->forTenant($tenant)->create(['plan_id' => Plan::factory()->limits($limits)->create()->id, 'status' => $status]);
}

it('enforces the product limit of the tenant\'s plan', function () {
    subscribe($this->m['tenant'], ['products' => 2]);
    $this->actingAs($this->m['owner'], 'sanctum');

    $this->postJson('/api/v1/products', ['name' => 'One', 'price' => 100])->assertCreated();
    $this->postJson('/api/v1/products', ['name' => 'Two', 'price' => 100])->assertCreated();
    $this->postJson('/api/v1/products', ['name' => 'Three', 'price' => 100])->assertStatus(409)->assertJsonPath('meta.errorCode', 'PLAN_LIMIT_REACHED');
});

it('enforces the custom domain limit of the plan', function () {
    subscribe($this->m['tenant'], ['custom_domains' => 1]);
    $this->actingAs($this->m['owner'], 'sanctum');

    $this->postJson('/api/v1/domains', ['domain' => 'one.com'])->assertCreated();
    $this->postJson('/api/v1/domains', ['domain' => 'two.com'])->assertStatus(409)->assertJsonPath('meta.errorCode', 'PLAN_LIMIT_REACHED');
});

it('blocks custom domains entirely on a plan that allows none', function () {
    subscribe($this->m['tenant'], ['custom_domains' => 0]);

    $this->actingAs($this->m['owner'], 'sanctum')->postJson('/api/v1/domains', ['domain' => 'one.com'])->assertStatus(409);
});

it('treats null limits as unlimited', function () {
    subscribe($this->m['tenant'], ['products' => null, 'custom_domains' => null]);
    $this->actingAs($this->m['owner'], 'sanctum');

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/products', ['name' => "P{$i}", 'price' => 100])->assertCreated();
    }
});

it('falls back to platform defaults without an entitled subscription', function () {
    subscribe($this->m['tenant'], ['products' => 0, 'custom_domains' => 0], SubscriptionStatus::Cancelled);
    config(['tenancy.custom_domains.max_per_tenant' => 2]);
    $this->actingAs($this->m['owner'], 'sanctum');

    $this->postJson('/api/v1/products', ['name' => 'Free', 'price' => 100])->assertCreated(); // unlimited by default
    $this->postJson('/api/v1/domains', ['domain' => 'one.com'])->assertCreated();
    $this->postJson('/api/v1/domains', ['domain' => 'two.com'])->assertCreated();
    $this->postJson('/api/v1/domains', ['domain' => 'three.com'])->assertStatus(409);
});

it('keeps limits per tenant', function () {
    $other = createMerchant('fashion');
    subscribe($this->m['tenant'], ['products' => 0]);

    $this->actingAs($this->m['owner'], 'sanctum')->postJson('/api/v1/products', ['name' => 'X', 'price' => 1])->assertStatus(409);
    $this->actingAs($other['owner'], 'sanctum')->postJson('/api/v1/products', ['name' => 'X', 'price' => 1])->assertCreated();
});

it('puts newly registered stores on the default plan when trials are off', function () {
    app(PlatformSettings::class)->update(['billing.trial_days' => 0]);
    $free = Plan::factory()->create(['slug' => 'free']);
    Plan::factory()->create(['slug' => 'ignored']);

    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1', 'store_name' => 'Brand', 'store_slug' => 'brand'])->assertCreated();

    $subscription = Tenant::query()->where('slug', 'brand')->first()->subscription;
    expect($subscription->plan_id)->toBe($free->id)->and($subscription->status)->toBe(SubscriptionStatus::Active);
});

it('skips the default plan when it is inactive or missing', function () {
    Plan::factory()->create(['slug' => 'free', 'is_active' => false]);

    $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1', 'store_name' => 'Brand', 'store_slug' => 'brand'])->assertCreated();

    expect(Tenant::query()->where('slug', 'brand')->first()->subscription)->toBeNull();
});

it('creates platform administrators from the CLI only, with a strong password', function () {
    $this->artisan('platform:create-admin', ['email' => 'Boss@Platform.test', '--password' => 'short'])->assertFailed();
    $this->artisan('platform:create-admin', ['email' => 'Boss@Platform.test', '--password' => 'a-long-enough-password', '--name' => 'Boss'])->assertSuccessful();

    $boss = User::where('email', 'boss@platform.test')->firstOrFail();
    expect($boss->role->value)->toBe('platform_admin')->and($boss->tenant_id)->toBeNull();

    $this->artisan('platform:create-admin', ['email' => 'boss@platform.test', '--password' => 'another-long-password'])->assertSuccessful();
    expect(User::where('email', 'boss@platform.test')->count())->toBe(1);
});
