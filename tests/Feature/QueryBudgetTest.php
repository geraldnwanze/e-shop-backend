<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Guards against N+1 queries: each busy endpoint must run the same number of queries whether the
 * store has a handful of rows or many. A new relation read inside a loop fails these tests.
 */

/** @return int Queries run while $call executes. */
function queriesFor(Closure $call): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $call();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Add $n products (each with variants, two images and a category) to the store. */
function fillCatalogue($tenant, int $n): void
{
    $category = Category::factory()->forTenant($tenant)->create();

    app(TenantContext::class)->runAs($tenant, function () use ($tenant, $category, $n): void {
        for ($i = 0; $i < $n; $i++) {
            $product = Product::factory()->forTenant($tenant)->create(['category_id' => $category->id, 'track_inventory' => true, 'stock' => 5]);
            ProductVariant::factory()->forProduct($product)->count(2)->create();

            foreach ([0, 1] as $position) {
                $media = Media::factory()->create(['tenant_id' => $tenant->id]);
                $product->images()->create(['media_id' => $media->id, 'alt' => 'x', 'position' => $position]);
            }
        }
    });
}

/** Every product of the store with its variants. */
function catalogue($tenant)
{
    return app(TenantContext::class)->runAs($tenant, fn () => Product::query()->with('variants')->get());
}

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->h = storefrontHeaders($this->a['tenant']);
    fillCatalogue($this->a['tenant'], 3);
});

it('lists storefront products in a fixed number of queries', function () {
    $call = fn () => http()->getJson('/api/v1/storefront/products?per_page=48', $this->h)->assertOk();
    $call();
    $few = queriesFor($call);

    fillCatalogue($this->a['tenant'], 12);

    expect(queriesFor($call))->toBe($few);
});

it('shows related products, categories and the store payload in a fixed number of queries', function () {
    $slug = Product::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->value('slug');

    foreach (["/api/v1/storefront/products/{$slug}/related", '/api/v1/storefront/categories', '/api/v1/storefront/store'] as $url) {
        $call = fn () => http()->getJson($url, $this->h)->assertOk();
        $call();
        $few = queriesFor($call);

        fillCatalogue($this->a['tenant'], 6);

        expect(queriesFor($call))->toBe($few, "{$url} changed its query count as the catalogue grew");
    }
});

it('reads a cart with many lines in a fixed number of queries', function () {
    $products = catalogue($this->a['tenant']);
    $cart = makeCart($this->a['tenant'], [[$products[0], 1, $products[0]->variants[0]]]);
    $call = fn () => http()->getJson("/api/v1/storefront/cart/{$cart->id}", $this->h)->assertOk();
    $call();
    $few = queriesFor($call);

    fillCatalogue($this->a['tenant'], 6);
    $more = catalogue($this->a['tenant']);
    app(TenantContext::class)->runAs($this->a['tenant'], function () use ($more, $cart, $products): void {
        foreach ($more->reject(fn ($product) => $product->id === $products[0]->id)->take(8) as $product) {
            $cart->items()->create(['product_id' => $product->id, 'variant_id' => $product->variants[0]->id, 'quantity' => 1]);
        }
    });

    expect(queriesFor($call))->toBe($few);
});

it('lists merchant products, orders and customers in a fixed number of queries', function () {
    $this->actingAs($this->a['owner'], 'sanctum');
    $products = catalogue($this->a['tenant']);
    $customer = Customer::factory()->forTenant($this->a['tenant'])->create();

    foreach (['/api/v1/products', '/api/v1/orders', '/api/v1/customers', '/api/v1/dashboard/stats'] as $url) {
        $call = fn () => $this->getJson($url)->assertOk();
        $call();
        $few = queriesFor($call);

        fillCatalogue($this->a['tenant'], 6);
        foreach (range(1, 4) as $i) {
            checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$products[0], 1, $products[0]->variants[0]]], $customer), token: $customer->createToken('t')->plainTextToken, key: "qb-{$url}-{$i}-".uniqid());
        }
        app('auth')->forgetGuards();
        $this->actingAs($this->a['owner'], 'sanctum');

        expect(queriesFor($call))->toBe($few, "{$url} changed its query count as data grew");
    }
});
