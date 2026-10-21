<?php

use App\Models\Category;
use App\Models\Product;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->cat = Category::factory()->forTenant($this->a['tenant'])->create(['slug' => 'classic']);
});

it('lists only active products of the resolved store', function () {
    Product::factory()->forTenant($this->a['tenant'])->count(2)->create();
    Product::factory()->forTenant($this->a['tenant'])->draft()->create();
    Product::factory()->forTenant($this->b['tenant'])->count(3)->create();

    http()->getJson('/api/v1/storefront/products', storefrontHeaders($this->a['tenant']))
        ->assertOk()->assertJsonPath('meta.total', 2);
    http()->getJson('/api/v1/storefront/products', storefrontHeaders($this->b['tenant']))
        ->assertOk()->assertJsonPath('meta.total', 3);
});

it('never exposes cost price or exact stock publicly', function () {
    $product = makeProduct($this->a['tenant'], ['cost_price' => 123, 'stock' => 42], [['stock' => 2]]);

    $json = http()->getJson("/api/v1/storefront/products/{$product->slug}", storefrontHeaders($this->a['tenant']))->assertOk();

    $json->assertJsonPath('data.inStock', true)->assertJsonMissingPath('data.costPrice')->assertJsonMissingPath('data.stock')
        ->assertJsonMissingPath('data.variants.0.stock');
    expect(json_encode($json->json()))->not->toContain('costPrice')->not->toContain('"stock"');
});

it('shows how many are left only when few remain, on products and on variants', function () {
    $h = storefrontHeaders($this->a['tenant']);
    $few = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => true, 'stock' => 3]);
    $plenty = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => true, 'stock' => 6]);
    $gone = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => true, 'stock' => 0]);
    $untracked = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => false, 'stock' => 2]);
    $shirt = makeProduct($this->a['tenant'], ['stock' => 8], [['stock' => 2], ['stock' => 40]]);

    http()->getJson("/api/v1/storefront/products/{$few->slug}", $h)->assertJsonPath('data.unitsLeft', 3);
    http()->getJson("/api/v1/storefront/products/{$plenty->slug}", $h)->assertJsonPath('data.unitsLeft', null);
    http()->getJson("/api/v1/storefront/products/{$gone->slug}", $h)->assertJsonPath('data.unitsLeft', null);
    http()->getJson("/api/v1/storefront/products/{$untracked->slug}", $h)->assertJsonPath('data.unitsLeft', null);

    $variants = http()->getJson("/api/v1/storefront/products/{$shirt->slug}", $h)->assertJsonPath('data.unitsLeft', null)->json('data.variants');
    expect(collect($variants)->pluck('unitsLeft')->sort()->values()->all())->toBe([null, 2]);
});

function setStockNotice(int $level): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['inventory' => ['low_stock_notice' => $level]])->assertOk();
    app('auth')->forgetGuards();
}

it('lets the merchant choose the level for the stock note, or switch it off', function () {
    $h = storefrontHeaders($this->a['tenant']);
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => true, 'stock' => 8]);
    $url = "/api/v1/storefront/products/{$product->slug}";

    http()->getJson($url, $h)->assertJsonPath('data.unitsLeft', null);

    setStockNotice(10);
    http()->getJson($url, $h)->assertJsonPath('data.unitsLeft', 8);
    expect(http()->getJson('/api/v1/storefront/products', $h)->json('data.0.unitsLeft'))->toBe(8);

    setStockNotice(0);
    http()->getJson($url, $h)->assertJsonPath('data.unitsLeft', null);
});

it('validates the stock note level', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    foreach ([-1, 101, 'many'] as $bad) {
        $this->putJson('/api/v1/store/settings', ['inventory' => ['low_stock_notice' => $bad]])->assertUnprocessable()
            ->assertJsonStructure(['meta' => ['errors' => ['inventory.low_stock_notice']]]);
    }
});

it('reports out-of-stock correctly', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 0]);

    http()->getJson("/api/v1/storefront/products/{$product->slug}", storefrontHeaders($this->a['tenant']))->assertJsonPath('data.inStock', false);
});

it('cannot read another store\'s product or a draft by slug', function () {
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create();
    $draft = Product::factory()->forTenant($this->a['tenant'])->draft()->create();

    http()->getJson("/api/v1/storefront/products/{$theirs->slug}", storefrontHeaders($this->a['tenant']))->assertNotFound();
    http()->getJson("/api/v1/storefront/products/{$draft->slug}", storefrontHeaders($this->a['tenant']))->assertNotFound();
});

it('filters, searches and sorts', function () {
    Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Alpha Cone', 'price' => 300, 'category_id' => $this->cat->id]);
    Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Beta Tub', 'price' => 100]);

    $h = storefrontHeaders($this->a['tenant']);
    http()->getJson('/api/v1/storefront/products?category=classic', $h)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Alpha Cone');
    http()->getJson('/api/v1/storefront/products?search=tub', $h)->assertJsonPath('meta.total', 1);
    http()->getJson('/api/v1/storefront/products?search=%25', $h)->assertJsonPath('meta.total', 0); // LIKE wildcard escaped
    http()->getJson('/api/v1/storefront/products?sort=price_asc', $h)->assertJsonPath('data.0.name', 'Beta Tub');
    http()->getJson('/api/v1/storefront/products?max_price=200', $h)->assertJsonPath('meta.total', 1);
    http()->getJson('/api/v1/storefront/products?sort=hack', $h)->assertUnprocessable();
});

it('lists active categories with active product counts', function () {
    Category::factory()->forTenant($this->a['tenant'])->create(['is_active' => false]);
    Category::factory()->forTenant($this->b['tenant'])->create();
    Product::factory()->forTenant($this->a['tenant'])->count(2)->create(['category_id' => $this->cat->id]);
    Product::factory()->forTenant($this->a['tenant'])->draft()->create(['category_id' => $this->cat->id]);

    http()->getJson('/api/v1/storefront/categories', storefrontHeaders($this->a['tenant']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.productsCount', 2);
});

it('suggests same-category products first, never the product itself, drafts or other stores', function () {
    $h = storefrontHeaders($this->a['tenant']);
    $other = Category::factory()->forTenant($this->a['tenant'])->create();
    $main = Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $this->cat->id]);
    $sameA = Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $this->cat->id]);
    $sameB = Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $this->cat->id]);
    Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $other->id]);
    Product::factory()->forTenant($this->a['tenant'])->draft()->create(['category_id' => $this->cat->id]);
    Product::factory()->forTenant($this->b['tenant'])->create();

    $ids = collect(http()->getJson("/api/v1/storefront/products/{$main->slug}/related", $h)->assertOk()->json('data'))->pluck('id');

    expect($ids)->toHaveCount(3)->not->toContain($main->id)
        ->and($ids->take(2)->sort()->values()->all())->toBe(collect([$sameA->id, $sameB->id])->sort()->values()->all());
});

it('puts sold-out products last and caps the list at four', function () {
    $h = storefrontHeaders($this->a['tenant']);
    $main = Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $this->cat->id]);
    $soldOut = Product::factory()->forTenant($this->a['tenant'])->create(['category_id' => $this->cat->id, 'track_inventory' => true, 'stock' => 0]);
    Product::factory()->forTenant($this->a['tenant'])->count(5)->create(['category_id' => $this->cat->id, 'track_inventory' => false]);

    $ids = collect(http()->getJson("/api/v1/storefront/products/{$main->slug}/related", $h)->assertOk()->json('data'))->pluck('id');

    expect($ids)->toHaveCount(4)->not->toContain($soldOut->id);
});

it('returns 404 suggestions for an unknown or hidden product', function () {
    $draft = Product::factory()->forTenant($this->a['tenant'])->draft()->create();
    $h = storefrontHeaders($this->a['tenant']);

    http()->getJson('/api/v1/storefront/products/nope/related', $h)->assertNotFound();
    http()->getJson("/api/v1/storefront/products/{$draft->slug}/related", $h)->assertNotFound();
});
