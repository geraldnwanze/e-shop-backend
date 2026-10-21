<?php

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->h = storefrontHeaders($this->a['tenant']);
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 5]);
});

function newCart(): string
{
    return http()->postJson('/api/v1/storefront/cart', [], test()->h)->assertCreated()->json('data.id');
}

it('creates a cart and adds items, merging duplicates', function () {
    $cart = newCart();

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 2], $this->h)->assertOk();
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 1], $this->h)
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.subtotal', 450000)->assertJsonPath('data.itemCount', 3);
});

it('prices lines from the database and ignores client prices', function () {
    $cart = newCart();

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 1, 'price' => 1, 'unit_price' => 1], $this->h)
        ->assertOk()->assertJsonPath('data.items.0.unitPrice', 150000);

    $this->product->update(['price' => 200000]);
    http()->getJson("/api/v1/storefront/cart/{$cart}", $this->h)->assertJsonPath('data.subtotal', 200000);
});

it('uses variant prices and requires a variant for products that have them', function () {
    $shirt = makeProduct($this->a['tenant'], ['price' => 1000], [['price' => 1500, 'stock' => 3], ['price' => null, 'stock' => 3]]);
    $cart = newCart();

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $shirt->id, 'quantity' => 1], $this->h)
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['variant_id']]]);
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $shirt->id, 'variant_id' => $shirt->variants[0]->id, 'quantity' => 2], $this->h)
        ->assertJsonPath('data.items.0.unitPrice', 1500)->assertJsonPath('data.subtotal', 3000);
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $shirt->id, 'variant_id' => $shirt->variants[1]->id, 'quantity' => 1], $this->h)
        ->assertJsonPath('data.subtotal', 4000); // null variant price falls back to product price
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'variant_id' => $shirt->variants[0]->id, 'quantity' => 1], $this->h)
        ->assertUnprocessable();
});

it('refuses quantities above available stock', function () {
    $cart = newCart();

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 6], $this->h)
        ->assertStatus(409)->assertJsonPath('meta.errorCode', 'INSUFFICIENT_STOCK')->assertJsonPath('meta.errors.available', 5);
});

it('allows unlimited quantity when inventory is not tracked', function () {
    $service = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => false, 'stock' => 0]);
    $cart = newCart();

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $service->id, 'quantity' => 50], $this->h)->assertOk();
});

it('rejects draft products, unknown products and other stores\' products', function () {
    $draft = Product::factory()->forTenant($this->a['tenant'])->draft()->create();
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create();
    $cart = newCart();

    foreach ([$draft->id, $theirs->id, fake()->uuid()] as $id) {
        http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $id, 'quantity' => 1], $this->h)->assertUnprocessable();
    }
});

it('updates and removes lines', function () {
    $cart = newCart();
    $item = http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 1], $this->h)->json('data.items.0.id');

    http()->patchJson("/api/v1/storefront/cart/{$cart}/items/{$item}", ['quantity' => 4], $this->h)->assertJsonPath('data.items.0.quantity', 4);
    http()->patchJson("/api/v1/storefront/cart/{$cart}/items/{$item}", ['quantity' => 0], $this->h)->assertUnprocessable();
    http()->patchJson("/api/v1/storefront/cart/{$cart}/items/{$item}", ['quantity' => 9], $this->h)->assertStatus(409);
    http()->deleteJson("/api/v1/storefront/cart/{$cart}/items/{$item}", [], $this->h)->assertOk()->assertJsonCount(0, 'data.items');
});

it('flags lines that became unavailable', function () {
    $cart = newCart();
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 3], $this->h);
    $this->product->update(['stock' => 1]);

    http()->getJson("/api/v1/storefront/cart/{$cart}", $this->h)->assertJsonPath('data.items.0.available', false);
});

it('cannot reach a cart from another store', function () {
    $cart = newCart();

    http()->getJson("/api/v1/storefront/cart/{$cart}", storefrontHeaders($this->b['tenant']))->assertNotFound();
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 1], storefrontHeaders($this->b['tenant']))->assertNotFound();
});

it('cannot address an item belonging to a different cart', function () {
    $mine = newCart();
    $other = newCart();
    $item = http()->postJson("/api/v1/storefront/cart/{$other}/items", ['product_id' => $this->product->id, 'quantity' => 1], $this->h)->json('data.items.0.id');

    http()->patchJson("/api/v1/storefront/cart/{$mine}/items/{$item}", ['quantity' => 2], $this->h)->assertNotFound();
    http()->deleteJson("/api/v1/storefront/cart/{$mine}/items/{$item}", [], $this->h)->assertNotFound();
});

it('binds carts created while signed in to that customer', function () {
    $customer = Customer::factory()->forTenant($this->a['tenant'])->create();
    $token = $customer->createToken('t')->plainTextToken;

    $cart = http()->postJson('/api/v1/storefront/cart', [], storefrontHeaders($this->a['tenant'], $token))->json('data.id');

    expect(Cart::withoutTenantScope()->where('id', $cart)->value('customer_id'))->toBe($customer->id);
    http()->getJson("/api/v1/storefront/cart/{$cart}", $this->h)->assertForbidden();
    http()->getJson("/api/v1/storefront/cart/{$cart}", storefrontHeaders($this->a['tenant'], $token))->assertOk();
});

it('refuses to modify a converted cart', function () {
    $cart = newCart();
    Cart::withoutTenantScope()->where('id', $cart)->update(['converted_at' => now()]);

    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => $this->product->id, 'quantity' => 1], $this->h)->assertStatus(409);
});

it('tells the shopper a checked-out cart is finished when it is read again', function () {
    $cart = newCart();
    http()->getJson("/api/v1/storefront/cart/{$cart}", $this->h)->assertOk();

    Cart::withoutTenantScope()->where('id', $cart)->update(['converted_at' => now()]);

    http()->getJson("/api/v1/storefront/cart/{$cart}", $this->h)->assertStatus(409)->assertJsonPath('meta.errorCode', 'CONFLICT');
});

function cartWithOneProduct(): string
{
    $cart = newCart();
    http()->postJson("/api/v1/storefront/cart/{$cart}/items", ['product_id' => test()->product->id, 'quantity' => 2], test()->h)->assertOk();

    return $cart;
}

it('previews what a discount code takes off without using it up', function () {
    Discount::factory()->forTenant($this->a['tenant'])->create(['code' => 'TEN', 'value' => 10, 'max_uses' => 5]);
    $cart = cartWithOneProduct();

    http()->postJson("/api/v1/storefront/cart/{$cart}/discount", ['code' => ' ten '], $this->h)
        ->assertOk()->assertJsonPath('data.code', 'TEN')->assertJsonPath('data.subtotal', 300000)
        ->assertJsonPath('data.discountTotal', 30000)->assertJsonPath('data.total', 270000);

    expect(Discount::withoutTenantScope()->where('code', 'TEN')->value('used_count'))->toBe(0);
});

it('rejects unknown, inactive, expired, used-up, below-minimum and other stores\' codes', function () {
    $t = $this->a['tenant'];
    Discount::factory()->forTenant($t)->create(['code' => 'OFF', 'is_active' => false]);
    Discount::factory()->forTenant($t)->create(['code' => 'OLD', 'ends_at' => now()->subDay()]);
    Discount::factory()->forTenant($t)->create(['code' => 'USED', 'max_uses' => 1, 'used_count' => 1]);
    Discount::factory()->forTenant($t)->create(['code' => 'BIG', 'min_subtotal' => 999999999]);
    Discount::factory()->forTenant($this->b['tenant'])->create(['code' => 'THEIRS']);
    $cart = cartWithOneProduct();

    foreach (['NOPE', 'OFF', 'OLD', 'USED', 'BIG', 'THEIRS'] as $code) {
        http()->postJson("/api/v1/storefront/cart/{$cart}/discount", ['code' => $code], $this->h)
            ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['code']]]);
    }
});

it('needs a code and refuses a checked-out cart', function () {
    $cart = cartWithOneProduct();

    http()->postJson("/api/v1/storefront/cart/{$cart}/discount", [], $this->h)->assertUnprocessable();

    Discount::factory()->forTenant($this->a['tenant'])->create(['code' => 'TEN']);
    Cart::withoutTenantScope()->where('id', $cart)->update(['converted_at' => now()]);

    http()->postJson("/api/v1/storefront/cart/{$cart}/discount", ['code' => 'TEN'], $this->h)->assertStatus(409);
});
