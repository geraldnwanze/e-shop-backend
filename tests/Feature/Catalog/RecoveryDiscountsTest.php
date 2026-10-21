<?php

use App\Models\Discount;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

function automaticCode(object $tenant, array $attributes = []): Discount
{
    $discount = Discount::factory()->forTenant($tenant)->create($attributes + ['max_uses' => 1, 'ends_at' => now()->addDays(7)]);
    Discount::withoutTenantScope()->whereKey($discount->getKey())->update(['source' => Discount::SOURCE_CART_RECOVERY]);

    return $discount->fresh() ?? Discount::withoutTenantScope()->find($discount->getKey());
}

it('lists only the merchant\'s own codes by default and the automatic ones on request', function () {
    $mine = Discount::factory()->forTenant($this->a['tenant'])->create(['code' => 'SUMMER10']);
    $auto = automaticCode($this->a['tenant'], ['code' => 'BACK-AAAAAA']);
    automaticCode($this->b['tenant'], ['code' => 'BACK-OTHER1']);

    $this->actingAs($this->a['owner'], 'sanctum');
    $this->getJson('/api/v1/discounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.isAutomatic', false);
    $this->getJson('/api/v1/discounts?source=automatic')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $auto->id)
        ->assertJsonPath('data.0.isAutomatic', true)->assertJsonPath('data.0.source', 'cart_recovery');
    $this->getJson('/api/v1/discounts?source=all')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/discounts?source=other')->assertUnprocessable();
});

it('creates dashboard codes as the merchant\'s own, whatever the request says', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/discounts', ['code' => 'NEWCODE', 'type' => 'percentage', 'value' => 10, 'source' => 'cart_recovery'])
        ->assertCreated()->assertJsonPath('data.source', 'manual');
});

it('lets a merchant switch an automatic code off or delete it but not rewrite it', function () {
    $auto = automaticCode($this->a['tenant']);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->putJson("/api/v1/discounts/{$auto->id}", ['value' => 90])->assertStatus(409);
    $this->putJson("/api/v1/discounts/{$auto->id}", ['code' => 'FREEFOREVER'])->assertStatus(409);
    $this->putJson("/api/v1/discounts/{$auto->id}", ['max_uses' => 1000])->assertStatus(409);
    expect($auto->fresh()->value)->toBe(10)->and($auto->fresh()->max_uses)->toBe(1);

    $this->putJson("/api/v1/discounts/{$auto->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.isActive', false);
    $this->deleteJson("/api/v1/discounts/{$auto->id}")->assertOk();
});

it('still lets a merchant edit their own codes freely', function () {
    $mine = Discount::factory()->forTenant($this->a['tenant'])->create();

    $this->actingAs($this->a['owner'], 'sanctum')->putJson("/api/v1/discounts/{$mine->id}", ['value' => 25, 'max_uses' => 5])->assertOk()->assertJsonPath('data.value', 25);
});

it('prunes recovery codes that expired unused a month ago and nothing else', function () {
    $stale = automaticCode($this->a['tenant'], ['ends_at' => now()->subDays(31)]);
    $staleOther = automaticCode($this->b['tenant'], ['ends_at' => now()->subDays(45)]);
    $recentlyExpired = automaticCode($this->a['tenant'], ['ends_at' => now()->subDays(5)]);
    $stillValid = automaticCode($this->a['tenant']);
    $used = automaticCode($this->a['tenant'], ['ends_at' => now()->subDays(40)]);
    Discount::withoutTenantScope()->whereKey($used->id)->update(['used_count' => 1]);
    $manual = Discount::factory()->forTenant($this->a['tenant'])->create(['ends_at' => now()->subDays(90)]);

    $this->artisan('discounts:prune-recovery-codes')->assertSuccessful();

    $remaining = Discount::withoutTenantScope()->pluck('id')->all();
    expect($remaining)->not->toContain($stale->id)->not->toContain($staleOther->id)
        ->and($remaining)->toContain($recentlyExpired->id)->toContain($stillValid->id)->toContain($used->id)->toContain($manual->id);
});
