<?php

use App\Models\Discount;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->actingAs($this->a['owner'], 'sanctum');
});

it('adjusts stock and logs the movement', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);

    $this->postJson("/api/v1/products/{$product->id}/inventory", ['delta' => 5, 'reason' => 'restock', 'note' => 'PO-1'])
        ->assertCreated()->assertJsonPath('data.stockAfter', 15);
    $this->postJson("/api/v1/products/{$product->id}/inventory", ['delta' => -3, 'reason' => 'manual_adjustment'])
        ->assertCreated()->assertJsonPath('data.stockAfter', 12);

    expect($product->fresh()->stock)->toBe(12);
    $this->getJson("/api/v1/products/{$product->id}/inventory")->assertOk()->assertJsonPath('meta.total', 2);
});

it('never lets stock go below zero', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 2]);

    $this->postJson("/api/v1/products/{$product->id}/inventory", ['delta' => -3, 'reason' => 'manual_adjustment'])->assertStatus(409);

    expect($product->fresh()->stock)->toBe(2)->and(InventoryMovement::count())->toBe(0);
});

it('rejects system-only reasons and zero deltas', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create();

    $this->postJson("/api/v1/products/{$product->id}/inventory", ['delta' => 1, 'reason' => 'order_placed'])->assertUnprocessable();
    $this->postJson("/api/v1/products/{$product->id}/inventory", ['delta' => 0, 'reason' => 'restock'])->assertUnprocessable();
});

it('adjusts a variant and refreshes the product total', function () {
    $product = makeProduct($this->a['tenant'], ['stock' => 0], [['stock' => 4], ['stock' => 6]]);
    $variant = $product->variants->first();

    $this->postJson("/api/v1/products/{$product->id}/inventory", ['variant_id' => $variant->id, 'delta' => 10, 'reason' => 'restock'])
        ->assertCreated()->assertJsonPath('data.stockAfter', 14);

    expect($product->fresh()->stock)->toBe(20);
});

it('rejects a variant from another product', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create();
    $other = ProductVariant::factory()->forProduct(Product::factory()->forTenant($this->a['tenant'])->create())->create();

    $this->postJson("/api/v1/products/{$product->id}/inventory", ['variant_id' => $other->id, 'delta' => 1, 'reason' => 'restock'])->assertUnprocessable();
});

it('manages discounts with validation and per-tenant uniqueness', function () {
    $this->postJson('/api/v1/discounts', ['code' => 'summer10', 'type' => 'percentage', 'value' => 10])
        ->assertCreated()->assertJsonPath('data.code', 'SUMMER10');
    $this->postJson('/api/v1/discounts', ['code' => 'SUMMER10', 'type' => 'fixed', 'value' => 500])->assertUnprocessable();
    $this->postJson('/api/v1/discounts', ['code' => 'BIG', 'type' => 'percentage', 'value' => 150])->assertUnprocessable();
    $this->postJson('/api/v1/discounts', ['code' => 'BAD CODE!', 'type' => 'fixed', 'value' => 5])->assertUnprocessable();

    Discount::factory()->forTenant($this->b['tenant'])->create(['code' => 'OTHER']);
    $this->postJson('/api/v1/discounts', ['code' => 'OTHER', 'type' => 'fixed', 'value' => 5])->assertCreated();
});

it('updates and deletes discounts but cannot touch another tenant\'s', function () {
    $mine = Discount::factory()->forTenant($this->a['tenant'])->create();
    $theirs = Discount::factory()->forTenant($this->b['tenant'])->create();

    $this->putJson("/api/v1/discounts/{$mine->id}", ['value' => 25])->assertOk()->assertJsonPath('data.value', 25);
    $this->putJson("/api/v1/discounts/{$theirs->id}", ['value' => 25])->assertNotFound();
    $this->deleteJson("/api/v1/discounts/{$theirs->id}")->assertNotFound();
    $this->deleteJson("/api/v1/discounts/{$mine->id}")->assertOk();
});

it('logs initial stock once: per variant, never twice for the product total', function () {
    $response = $this->postJson('/api/v1/products', ['name' => 'Shirt', 'price' => 1000, 'variants' => [['options' => ['Size' => 'S'], 'stock' => 5], ['options' => ['Size' => 'M'], 'stock' => 7]]])->assertCreated();
    $product = Product::withoutTenantScope()->where('id', $response->json('data.id'))->firstOrFail();

    $movements = InventoryMovement::withoutTenantScope()->where('product_id', $product->id)->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->every(fn ($m) => $m->variant_id !== null))->toBeTrue()
        ->and($movements->sum('delta'))->toBe(12);

    $plain = $this->postJson('/api/v1/products', ['name' => 'Cone', 'price' => 100, 'stock' => 9])->json('data.id');
    $plainProduct = Product::withoutTenantScope()->where('id', $plain)->firstOrFail();
    expect(InventoryMovement::withoutTenantScope()->where('product_id', $plainProduct->id)->pluck('delta')->all())->toBe([9]);
});
