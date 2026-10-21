<?php

use App\Exceptions\MissingTenantContextException;
use App\Models\Domain;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'icecream']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'fashion']);
    $this->storeA = Store::factory()->forTenant($this->tenantA)->create();
    $this->storeB = Store::factory()->forTenant($this->tenantB)->create();
    $this->context = app(TenantContext::class);
});

it('only returns the current tenant\'s records', function () {
    $this->context->runAs($this->tenantA, function () {
        expect(Store::query()->pluck('id')->all())->toBe([$this->storeA->id]);
    });

    $this->context->runAs($this->tenantB, function () {
        expect(Store::query()->pluck('id')->all())->toBe([$this->storeB->id]);
    });
});

it('fails closed when no tenant is in context', function () {
    expect(Store::query()->count())->toBe(0);
});

it('cannot find another tenant\'s record by id', function () {
    $this->context->runAs($this->tenantA, function () {
        expect(Store::query()->find($this->storeB->id))->toBeNull();
        Store::query()->findOrFail($this->storeB->id);
    });
})->throws(ModelNotFoundException::class);

it('assigns tenant_id from context on create and ignores client-supplied values', function () {
    $this->context->runAs($this->tenantA, function () {
        $domain = new Domain;
        $domain->forceFill(['domain' => 'x.test', 'type' => 'CUSTOM', 'status' => 'pending']);
        $domain->save();

        expect($domain->tenant_id)->toBe($this->tenantA->id);

        // mass assignment of tenant_id is not possible
        $mass = Store::create(['name' => 'Mass', 'slug' => 'mass', 'tenant_id' => $this->tenantB->id]);
        expect($mass->tenant_id)->toBe($this->tenantA->id);
    });
});

it('refuses to create tenant-owned records without a tenant', function () {
    Store::create(['name' => 'Orphan', 'slug' => 'orphan']);
})->throws(MissingTenantContextException::class);

it('refuses to create a record for a different tenant than the current one', function () {
    $this->context->runAs($this->tenantA, function () {
        $store = new Store(['name' => 'X', 'slug' => 'x-store']);
        $store->tenant_id = $this->tenantB->id;
        $store->save();
    });
})->throws(LogicException::class);

it('makes tenant_id immutable', function () {
    $this->context->runAs($this->tenantA, function () {
        $store = Store::query()->firstOrFail();
        $store->tenant_id = $this->tenantB->id;
        $store->save();
    });
})->throws(LogicException::class);

it('only crosses tenants through the explicit bypass', function () {
    $count = $this->context->withoutTenantScope(fn () => Store::query()->count());

    expect($count)->toBe(2)
        ->and($this->context->isBypassed())->toBeFalse()
        ->and(Store::query()->count())->toBe(0);
});

it('restores the previous tenant after runAs', function () {
    $this->context->set($this->tenantA);

    $this->context->runAs($this->tenantB, fn () => expect($this->context->id())->toBe($this->tenantB->id));

    expect($this->context->id())->toBe($this->tenantA->id);
});

it('does not leak tenant context between requests', function () {
    $this->getJson('/api/v1/storefront/store', ['X-Storefront-Host' => 'icecream.mydomain.test'])->assertOk();

    expect($this->context->hasTenant())->toBeFalse();
});

it('scopes route-model binding for tenant-owned models', function () {
    $this->context->runAs($this->tenantA, function () {
        expect(fn () => (new Store)->resolveRouteBinding($this->storeB->id))->not->toThrow(Throwable::class)
            ->and((new Store)->resolveRouteBinding($this->storeB->id))->toBeNull();
    });
});

it('does not apply the tenant scope to users, which are resolved before a tenant exists', function () {
    $user = User::factory()->forTenant($this->tenantA)->create();

    expect(User::query()->find($user->id))->not->toBeNull();
});
