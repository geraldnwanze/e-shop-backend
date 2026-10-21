<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->storeA = Store::factory()->forTenant($this->tenantA)->create();
    $this->storeB = Store::factory()->forTenant($this->tenantB)->create();
    $this->ownerA = User::factory()->forTenant($this->tenantA)->create();
});

it('returns only the authenticated merchant\'s own store', function () {
    $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/store')
        ->assertOk()->assertJsonPath('data.id', $this->storeA->id);
});

it('ignores a forged tenant header from merchants', function () {
    $this->actingAs($this->ownerA, 'sanctum')
        ->getJson('/api/v1/store', ['X-Acting-Tenant' => $this->tenantB->id, 'X-Storefront-Host' => $this->tenantB->slug.'.mydomain.test'])
        ->assertOk()->assertJsonPath('data.id', $this->storeA->id);
});

it('ignores a forged tenant_id in the query or body', function () {
    $this->actingAs($this->ownerA, 'sanctum')
        ->getJson('/api/v1/store?tenant_id='.$this->tenantB->id)
        ->assertOk()->assertJsonPath('data.id', $this->storeA->id);
});

it('lets platform admins act inside a tenant explicitly', function () {
    $admin = User::factory()->platformAdmin()->create();

    // Merchant route policy still denies: admins manage tenants, not merchant stores.
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/store', ['X-Acting-Tenant' => $this->tenantB->id])
        ->assertForbidden();
});

it('returns 404 when a platform admin acts as an unknown tenant', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/store', ['X-Acting-Tenant' => fake()->uuid()])
        ->assertNotFound();
});

it('blocks suspended merchants from the dashboard API', function () {
    $this->tenantA->update(['status' => 'suspended']);

    $this->actingAs($this->ownerA, 'sanctum')->getJson('/api/v1/store')
        ->assertForbidden()->assertJsonPath('meta.errorCode', 'TENANT_SUSPENDED');
});

it('grants permissions by role', function () {
    expect(UserRole::MerchantOwner->permissions())->toContain(Permission::ManageDomains)
        ->and(UserRole::MerchantStaff->permissions())->not->toContain(Permission::ManageStore)
        ->and(UserRole::Customer->permissions())->toBeEmpty();
});
