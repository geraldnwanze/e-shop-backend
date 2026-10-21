<?php

use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    $this->tenant = Tenant::factory()->create();
    Store::factory()->forTenant($this->tenant)->create();
    $this->merchant = User::factory()->forTenant($this->tenant)->create();
});

it('lets platform admins list and view tenants', function () {
    $this->actingAs($this->admin, 'sanctum');

    $this->getJson('/api/v1/admin/tenants')->assertOk()->assertJsonPath('data.0.id', $this->tenant->id)
        ->assertJsonStructure(['meta' => ['currentPage', 'total']]);
    $this->getJson("/api/v1/admin/tenants/{$this->tenant->id}")->assertOk()->assertJsonPath('data.slug', $this->tenant->slug);
});

it('hides platform admin APIs from merchants', function () {
    $this->actingAs($this->merchant, 'sanctum');

    $this->getJson('/api/v1/admin/tenants')->assertForbidden();
    $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/suspend")->assertForbidden();
    expect($this->tenant->fresh()->isActive())->toBeTrue();
});

it('rejects unauthenticated access to admin APIs', function () {
    $this->getJson('/api/v1/admin/tenants')->assertUnauthorized();
});

it('suspends a tenant, revokes its tokens and blocks the storefront and dashboard', function () {
    $token = $this->merchant->createToken('t')->plainTextToken;

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/suspend")
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    expect($this->merchant->tokens()->count())->toBe(0);

    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => $this->tenant->slug.'.mydomain.test'])
        ->assertForbidden();
});

it('reactivates a suspended tenant', function () {
    $this->tenant->update(['status' => 'suspended']);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/v1/admin/tenants/{$this->tenant->id}/activate")
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('returns 404 for unknown tenants', function () {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/tenants/'.fake()->uuid())
        ->assertNotFound()->assertJsonPath('meta.errorCode', 'NOT_FOUND');
});
