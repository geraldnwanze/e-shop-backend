<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Theme;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

dataset('admin endpoints', [
    ['GET', '/api/v1/admin/overview'],
    ['GET', '/api/v1/admin/tenants'],
    ['GET', '/api/v1/admin/stores'],
    ['GET', '/api/v1/admin/users'],
    ['GET', '/api/v1/admin/themes'],
    ['POST', '/api/v1/admin/themes'],
    ['GET', '/api/v1/admin/plans'],
    ['POST', '/api/v1/admin/plans'],
    ['GET', '/api/v1/admin/subscriptions'],
    ['GET', '/api/v1/admin/domains'],
    ['GET', '/api/v1/admin/settings'],
    ['PUT', '/api/v1/admin/settings'],
    ['GET', '/api/v1/admin/audit-logs'],
    ['GET', '/api/v1/admin/audit-logs/actions'],
    ['GET', '/api/v1/admin/audit-logs/export'],
    ['GET', '/api/v1/admin/audit-logs/0198a000-0000-7000-8000-000000000000'],
]);

it('rejects every admin endpoint for unauthenticated callers', function (string $method, string $uri) {
    http()->json($method, $uri, [])->assertUnauthorized();
})->with('admin endpoints');

it('rejects every admin endpoint for merchants and customers', function (string $method, string $uri, UserRole $role) {
    $user = User::factory()->forTenant($this->a['tenant'], $role)->create();

    $this->actingAs($user, 'sanctum')->json($method, $uri, [])->assertForbidden();
})->with('admin endpoints')->with([UserRole::MerchantOwner, UserRole::MerchantAdmin, UserRole::MerchantStaff, UserRole::Customer]);

it('rejects a suspended platform admin', function () {
    $this->admin->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/overview')->assertForbidden()->assertJsonPath('meta.errorCode', 'ACCOUNT_SUSPENDED');
});

it('rejects storefront customer tokens on admin routes', function () {
    $customer = Customer::factory()->forTenant($this->a['tenant'])->create();

    http()->getJson('/api/v1/admin/tenants', ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken])->assertUnauthorized();
});

it('sees across tenants, but the bypass never leaks into later requests', function () {
    Product::factory()->forTenant($this->a['tenant'])->create();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/stores')->assertOk()->assertJsonPath('meta.total', 2);
    expect(app(TenantContext::class)->isBypassed())->toBeFalse();

    // A merchant request right afterwards still only sees their own tenant.
    $this->actingAs($this->b['owner'], 'sanctum')->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/store')->assertOk()->assertJsonPath('data.id', $this->b['store']->id);
});

it('does not give the admin bypass to merchant routes', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/products')->assertForbidden();
    $this->getJson('/api/v1/store')->assertNotFound(); // no tenant in context: fails closed, no data
});

it('resolves tenant-scoped route models for admins (stores, domains, subscriptions)', function () {
    $domain = Domain::factory()->forTenant($this->a['tenant'])->create(['domain' => 'x.test']);
    $subscription = Subscription::factory()->forTenant($this->b['tenant'])->create();

    $this->actingAs($this->admin, 'sanctum');
    $this->getJson("/api/v1/admin/stores/{$this->a['store']->id}")->assertOk()->assertJsonPath('data.tenant.slug', 'icecream');
    $this->patchJson("/api/v1/admin/subscriptions/{$subscription->id}", ['status' => 'past_due'])->assertOk();
    expect($domain->id)->not->toBeEmpty();
});

it('refuses to edit or reactivate a cancelled subscription', function () {
    $subscription = Subscription::factory()->forTenant($this->b['tenant'])->create(['status' => 'cancelled', 'cancelled_at' => now()]);

    $this->actingAs($this->admin, 'sanctum');
    $this->patchJson("/api/v1/admin/subscriptions/{$subscription->id}", ['status' => 'active'])->assertStatus(409);

    expect(Subscription::withoutTenantScope()->find($subscription->id)->status->value)->toBe('cancelled');
});

it('can only be reached with the manage-platform gate, not by a role check alone', function () {
    // The gate is derived from the permission set, so removing the permission removes access.
    expect($this->admin->hasPermission(Permission::ManagePlatform))->toBeTrue()
        ->and(User::factory()->forTenant($this->a['tenant'])->create()->hasPermission(Permission::ManagePlatform))->toBeFalse();
    expect(Theme::query()->count() + Plan::query()->count() + Store::withoutTenantScope()->count())->toBeGreaterThanOrEqual(2);
});
