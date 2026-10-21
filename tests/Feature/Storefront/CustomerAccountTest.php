<?php

use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

function registerCustomer($tenant, array $overrides = [])
{
    return http()->postJson('/api/v1/storefront/auth/register', $overrides + [
        'email' => 'Shopper@Example.com', 'first_name' => 'Sam', 'last_name' => 'Shopper',
        'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
    ], storefrontHeaders($tenant));
}

it('registers, logs in and reads the profile', function () {
    registerCustomer($this->a['tenant'])->assertCreated()->assertJsonPath('data.customer.email', 'shopper@example.com')->assertJsonPath('data.customer.hasAccount', true);

    $token = http()->postJson('/api/v1/storefront/auth/login', ['email' => 'SHOPPER@example.com', 'password' => 'secret-pass1'], storefrontHeaders($this->a['tenant']))
        ->assertOk()->json('data.token');

    http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->a['tenant'], $token))->assertOk()->assertJsonPath('data.firstName', 'Sam');
    http()->postJson('/api/v1/storefront/auth/logout', [], storefrontHeaders($this->a['tenant'], $token))->assertOk();
    http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->a['tenant'], $token))->assertUnauthorized();
});

it('scopes accounts to the store: same email can register on two stores independently', function () {
    registerCustomer($this->a['tenant'])->assertCreated();
    registerCustomer($this->b['tenant'])->assertCreated();
    registerCustomer($this->a['tenant'])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['email']]]);

    expect(Customer::withoutTenantScope()->where('email', 'shopper@example.com')->count())->toBe(2);
});

it('does not let an account on one store log in to another', function () {
    registerCustomer($this->a['tenant'])->assertCreated();

    http()->postJson('/api/v1/storefront/auth/login', ['email' => 'shopper@example.com', 'password' => 'secret-pass1'], storefrontHeaders($this->b['tenant']))
        ->assertUnauthorized()->assertJsonPath('meta.errorCode', 'INVALID_CREDENTIALS');
});

it('rejects a store A token on store B', function () {
    $token = registerCustomer($this->a['tenant'])->json('data.token');

    http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->a['tenant'], $token))->assertOk();
    http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->b['tenant'], $token))->assertUnauthorized();
    http()->getJson('/api/v1/storefront/account/orders', storefrontHeaders($this->b['tenant'], $token))->assertUnauthorized();
});

it('keeps merchant and customer tokens on their own routes', function () {
    $customerToken = registerCustomer($this->a['tenant'])->json('data.token');
    $merchantToken = $this->a['owner']->createToken('m')->plainTextToken;

    http()->getJson('/api/v1/store', ['Authorization' => "Bearer {$customerToken}"])->assertUnauthorized(); // customer can't even load without a tenant in context
    http()->getJson('/api/v1/orders', ['Authorization' => "Bearer {$customerToken}"])->assertUnauthorized(); // customer can't even load without a tenant in context
    http()->getJson('/api/v1/admin/tenants', ['Authorization' => "Bearer {$customerToken}"])->assertUnauthorized(); // customer can't even load without a tenant in context
    http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->a['tenant'], $merchantToken))->assertForbidden();
});

it('lets a guest claim their record without gaining access to earlier guest orders', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5]);
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]), ['customer' => ['email' => 'shopper@example.com']])->assertCreated();

    $token = registerCustomer($this->a['tenant'])->assertCreated()->json('data.token');

    http()->getJson('/api/v1/storefront/account/orders', storefrontHeaders($this->a['tenant'], $token))->assertOk()->assertJsonCount(0, 'data');
    expect(Customer::withoutTenantScope()->where('email', 'shopper@example.com')->count())->toBe(1);
});

it('shows a customer only their own signed-in orders', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);
    $me = Customer::factory()->forTenant($this->a['tenant'])->create();
    $other = Customer::factory()->forTenant($this->a['tenant'])->create();
    $myToken = $me->createToken('t')->plainTextToken;

    $mine = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]], $me), token: $myToken, key: 'acct-key-00001')->json('data.id');
    $theirs = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]], $other), token: $other->createToken('t')->plainTextToken, key: 'acct-key-00002')->json('data.id');

    $h = storefrontHeaders($this->a['tenant'], $myToken);
    http()->getJson('/api/v1/storefront/account/orders', $h)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    http()->getJson("/api/v1/storefront/account/orders/{$mine}", $h)->assertOk()
        ->assertJsonPath('data.items.0.productId', $product->id)->assertJsonPath('data.items.0.variantId', null);
    http()->getJson("/api/v1/storefront/account/orders/{$theirs}", $h)->assertNotFound();
});

it('manages an address book that is private to each customer', function () {
    $me = Customer::factory()->forTenant($this->a['tenant'])->create();
    $other = Customer::factory()->forTenant($this->a['tenant'])->create();
    $theirs = Address::factory()->create(['customer_id' => $other->id, 'tenant_id' => $this->a['tenant']->id]);
    $h = storefrontHeaders($this->a['tenant'], $me->createToken('t')->plainTextToken);
    $payload = ['first_name' => 'Ada', 'last_name' => 'Obi', 'line1' => '1 Marina', 'city' => 'Lagos', 'country' => 'NG', 'is_default' => true];

    $id = http()->postJson('/api/v1/storefront/account/addresses', $payload, $h)->assertCreated()->json('data.id');
    http()->postJson('/api/v1/storefront/account/addresses', ['country' => 'Nigeria'] + $payload, $h)->assertUnprocessable();
    http()->getJson('/api/v1/storefront/account/addresses', $h)->assertJsonCount(1, 'data');
    http()->deleteJson("/api/v1/storefront/account/addresses/{$theirs->id}", [], $h)->assertNotFound();
    http()->deleteJson("/api/v1/storefront/account/addresses/{$id}", [], $h)->assertOk();
    expect(Address::withoutTenantScope()->find($theirs->id))->not->toBeNull();
});

it('tracks an order by order number alone without exposing personal details', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5]);
    $number = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]))->json('data.orderNumber');
    $h = storefrontHeaders($this->a['tenant']);

    http()->postJson('/api/v1/storefront/orders/track', ['order_number' => strtolower($number)], $h)
        ->assertOk()->assertJsonPath('data.orderNumber', $number)->assertJsonPath('data.status', 'PENDING')
        ->assertJsonMissingPath('data.shippingAddress')->assertJsonMissingPath('data.customer')
        ->assertJsonMissingPath('data.email');
    http()->postJson('/api/v1/storefront/orders/track', ['order_number' => 'ORD-2026-999999'], $h)->assertNotFound();
    http()->postJson('/api/v1/storefront/orders/track', [], $h)->assertUnprocessable();
});

it('numbers orders per store so the same number resolves to each store\'s own order', function () {
    $productA = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5, 'name' => 'Alpha Item']);
    $productB = Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5, 'name' => 'Beta Item']);
    $numberA = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$productA, 1]]))->json('data.orderNumber');
    $numberB = checkout($this->b['tenant'], makeCart($this->b['tenant'], [[$productB, 1]]))->json('data.orderNumber');

    expect($numberB)->toBe($numberA);

    http()->postJson('/api/v1/storefront/orders/track', ['order_number' => $numberA], storefrontHeaders($this->a['tenant']))
        ->assertOk()->assertJsonPath('data.items.0.name', 'Alpha Item');
    http()->postJson('/api/v1/storefront/orders/track', ['order_number' => $numberB], storefrontHeaders($this->b['tenant']))
        ->assertOk()->assertJsonPath('data.items.0.name', 'Beta Item');
});

it('throttles customer login attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        http()->postJson('/api/v1/storefront/auth/login', ['email' => 'x@y.com', 'password' => 'bad'], storefrontHeaders($this->a['tenant']));
    }

    http()->postJson('/api/v1/storefront/auth/login', ['email' => 'x@y.com', 'password' => 'bad'], storefrontHeaders($this->a['tenant']))->assertStatus(429);
});

it('keeps orders unreachable by other tenants\' model queries', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5]);
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]))->assertCreated();

    app(TenantContext::class)->runAs($this->b['tenant'], function () {
        expect(Order::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0);
    });
});

function wishlistSession($tenant): array
{
    $token = registerCustomer($tenant)->assertCreated()->json('data.token');

    return storefrontHeaders($tenant, $token);
}

it('saves, lists and removes wishlist products without duplicates', function () {
    $h = wishlistSession($this->a['tenant']);
    $product = Product::factory()->forTenant($this->a['tenant'])->create();

    http()->putJson("/api/v1/storefront/account/wishlist/{$product->id}", [], $h)->assertOk();
    http()->putJson("/api/v1/storefront/account/wishlist/{$product->id}", [], $h)->assertOk();

    http()->getJson('/api/v1/storefront/account/wishlist', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);

    http()->deleteJson("/api/v1/storefront/account/wishlist/{$product->id}", [], $h)->assertOk();
    http()->getJson('/api/v1/storefront/account/wishlist', $h)->assertJsonCount(0, 'data');
});

it('keeps wishlists private to the customer and hides withdrawn products', function () {
    $mine = wishlistSession($this->a['tenant']);
    $product = Product::factory()->forTenant($this->a['tenant'])->create();
    http()->putJson("/api/v1/storefront/account/wishlist/{$product->id}", [], $mine)->assertOk();

    $other = storefrontHeaders($this->a['tenant'], registerCustomer($this->a['tenant'], ['email' => 'other@example.com'])->json('data.token'));
    http()->getJson('/api/v1/storefront/account/wishlist', $other)->assertJsonCount(0, 'data');

    $product->forceFill(['status' => 'draft'])->save();
    http()->getJson('/api/v1/storefront/account/wishlist', $mine)->assertJsonCount(0, 'data');
});

it('cannot save another store\'s product or use the wishlist signed out', function () {
    $h = wishlistSession($this->a['tenant']);
    $foreign = Product::factory()->forTenant($this->b['tenant'])->create();

    http()->putJson("/api/v1/storefront/account/wishlist/{$foreign->id}", [], $h)->assertNotFound();
    http()->getJson('/api/v1/storefront/account/wishlist', storefrontHeaders($this->a['tenant']))->assertUnauthorized();
});
