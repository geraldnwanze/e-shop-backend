<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->actingAs($this->a['owner'], 'sanctum');
});

it('creates a product with variants and images', function () {
    $category = Category::factory()->forTenant($this->a['tenant'])->create();
    $media = Media::factory()->forTenant($this->a['tenant'])->create();

    $response = $this->postJson('/api/v1/products', [
        'name' => 'Vanilla Tub', 'price' => 250000, 'compare_at_price' => 300000, 'cost_price' => 100000,
        'status' => 'active', 'track_inventory' => true, 'category_id' => $category->id,
        'variants' => [
            ['options' => ['Size' => 'Large', 'Color' => 'Red'], 'stock' => 5, 'sku' => 'V-L-R'],
            ['options' => ['Size' => 'Small', 'Color' => 'Red'], 'stock' => 7, 'price' => 200000],
        ],
        'images' => [['media_id' => $media->id, 'alt' => 'Front']],
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'vanilla-tub')
        ->assertJsonPath('data.price', 250000)
        ->assertJsonPath('data.stock', 12)
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.variants.0.name', 'Large / Red')
        ->assertJsonPath('data.variants.1.price', 200000)
        ->assertJsonPath('data.images.0.url', $media->url);

    $product = Product::withoutTenantScope()->where('id', $response->json('data.id'))->firstOrFail();
    expect($product->tenant_id)->toBe($this->a['tenant']->id)
        ->and(ProductVariant::withoutTenantScope()->where('product_id', $product->id)->count())->toBe(2);
});

it('generates unique slugs per tenant', function () {
    Product::factory()->forTenant($this->b['tenant'])->create(['slug' => 'cone']);

    $this->postJson('/api/v1/products', ['name' => 'Cone', 'price' => 100])->assertJsonPath('data.slug', 'cone');
    $this->postJson('/api/v1/products', ['name' => 'Cone', 'price' => 100])->assertJsonPath('data.slug', 'cone-2');
});

it('ignores client-supplied tenant_id', function () {
    $response = $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 100, 'tenant_id' => $this->b['tenant']->id])->assertCreated();

    expect(Product::withoutTenantScope()->where('id', $response->json('data.id'))->value('tenant_id'))->toBe($this->a['tenant']->id);
});

it('validates money as non-negative integers in minor units', function (array $payload, string $field) {
    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 100, ...$payload])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'decimal price' => [['price' => 19.99], 'price'],
    'negative price' => [['price' => -1], 'price'],
    'string price' => [['price' => 'free'], 'price'],
    'bad status' => [['status' => 'published'], 'status'],
    'negative stock' => [['stock' => -5], 'stock'],
    'variant without options' => [['variants' => [['stock' => 1]]], 'variants.0.options'],
    'duplicate variant skus' => [['variants' => [['options' => ['S' => 'a'], 'sku' => 'D'], ['options' => ['S' => 'b'], 'sku' => 'D']]], 'variants.0.sku'],
]);

it('rejects another tenant\'s category and media', function () {
    $foreignCategory = Category::factory()->forTenant($this->b['tenant'])->create();
    $foreignMedia = Media::factory()->forTenant($this->b['tenant'])->create();

    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 100, 'category_id' => $foreignCategory->id])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['category_id']]]);
    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 100, 'images' => [['media_id' => $foreignMedia->id]]])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['images.0.media_id']]]);
});

it('keeps SKUs unique across products and variants within a tenant', function () {
    Product::factory()->forTenant($this->a['tenant'])->create(['sku' => 'TAKEN']);

    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 1, 'sku' => 'TAKEN'])->assertUnprocessable();
    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 1, 'variants' => [['options' => ['a' => 'b'], 'sku' => 'TAKEN']]])->assertUnprocessable();

    // another tenant may reuse the same SKU
    Product::factory()->forTenant($this->b['tenant'])->create(['sku' => 'SHARED']);
    $this->postJson('/api/v1/products', ['name' => 'X', 'price' => 1, 'sku' => 'SHARED'])->assertCreated();
});

it('updates fields and syncs variants, keeping ids of existing ones', function () {
    $id = $this->postJson('/api/v1/products', [
        'name' => 'Shirt', 'price' => 1000,
        'variants' => [['options' => ['Size' => 'S'], 'stock' => 3], ['options' => ['Size' => 'M'], 'stock' => 4]],
    ])->json('data');
    $keep = $id['variants'][0]['id'];

    $this->putJson("/api/v1/products/{$id['id']}", [
        'name' => 'Shirt v2', 'variants' => [
            ['id' => $keep, 'options' => ['Size' => 'S'], 'stock' => 9],
            ['options' => ['Size' => 'XL'], 'stock' => 1],
        ],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Shirt v2')
        ->assertJsonPath('data.slug', 'shirt')
        ->assertJsonPath('data.stock', 10)
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.variants.0.id', $keep);

    expect(ProductVariant::withoutTenantScope()->count())->toBe(2);
});

it('leaves variants untouched when the key is absent', function () {
    $id = $this->postJson('/api/v1/products', ['name' => 'Shirt', 'price' => 1000, 'variants' => [['options' => ['Size' => 'S'], 'stock' => 3]]])->json('data.id');

    $this->patchJson("/api/v1/products/{$id}", ['price' => 2000])->assertOk()->assertJsonCount(1, 'data.variants');
});

it('rejects variant ids that belong to another product', function () {
    $other = Product::factory()->forTenant($this->a['tenant'])->create();
    $variant = ProductVariant::factory()->forProduct($other)->create();
    $mine = $this->postJson('/api/v1/products', ['name' => 'Mine', 'price' => 1])->json('data.id');

    $this->putJson("/api/v1/products/{$mine}", ['variants' => [['id' => $variant->id, 'options' => ['a' => 'b']]]])->assertUnprocessable();
});

it('soft deletes products and hides them everywhere', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create();

    $this->deleteJson("/api/v1/products/{$product->id}")->assertOk();

    $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    expect(Product::withoutTenantScope()->withTrashed()->find($product->id)->trashed())->toBeTrue();
});

it('lists, filters and paginates products', function () {
    Product::factory()->forTenant($this->a['tenant'])->count(3)->create();
    Product::factory()->forTenant($this->a['tenant'])->draft()->create(['name' => 'Secret Draft']);
    Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Low', 'stock' => 2]);

    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 5);
    $this->getJson('/api/v1/products?status=draft')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/products?search=Secret')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/products?low_stock=1')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/products?status=nope')->assertUnprocessable();
});

it('enforces role permissions', function () {
    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $customerRole = User::factory()->forTenant($this->a['tenant'], UserRole::Customer)->create();

    $this->actingAs($staff, 'sanctum')->postJson('/api/v1/products', ['name' => 'Staff', 'price' => 1])->assertCreated();
    $this->actingAs($customerRole, 'sanctum')->getJson('/api/v1/products')->assertForbidden();
    $this->actingAs($customerRole, 'sanctum')->postJson('/api/v1/products', ['name' => 'X', 'price' => 1])->assertForbidden();
});

it('manages categories with slug generation and parent validation', function () {
    $parent = $this->postJson('/api/v1/categories', ['name' => 'Ice Cream'])->assertCreated()->assertJsonPath('data.slug', 'ice-cream')->json('data.id');
    $child = $this->postJson('/api/v1/categories', ['name' => 'Ice Cream', 'parent_id' => $parent])->assertJsonPath('data.slug', 'ice-cream-2')->json('data.id');

    $this->putJson("/api/v1/categories/{$child}", ['parent_id' => $child])->assertStatus(422);
    $this->putJson("/api/v1/categories/{$child}", ['name' => 'Sorbet', 'is_active' => false])->assertOk()->assertJsonPath('data.isActive', false);
    $this->getJson('/api/v1/categories')->assertJsonCount(2, 'data');
    $this->deleteJson("/api/v1/categories/{$child}")->assertOk();
    $this->getJson('/api/v1/categories')->assertJsonCount(1, 'data');
});
