<?php

use App\Enums\PaymentProviderType;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Support\Tenancy\TenantContext;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Create a tenant with store, owner and the default setup used by dashboard tests.
 *
 * @return array{tenant: Tenant, store: Store, owner: User}
 */
function createMerchant(string $slug = 'icecream', UserRole $role = UserRole::MerchantOwner): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    $store = Store::factory()->forTenant($tenant)->create();
    $owner = User::factory()->forTenant($tenant, $role)->create();

    return compact('tenant', 'store', 'owner');
}

/*
|--------------------------------------------------------------------------
| Storefront / catalogue helpers
|--------------------------------------------------------------------------
*/

/**
 * @return array<string, string>
 */
function storefrontHeaders(Tenant $tenant, ?string $token = null, ?string $idempotencyKey = null): array
{
    return array_filter([
        'X-Storefront-Host' => $tenant->slug.'.mydomain.test',
        'Authorization' => $token ? 'Bearer '.$token : null,
        'Idempotency-Key' => $idempotencyKey,
    ]);
}

/**
 * Run an HTTP call with a clean auth-guard cache (the app instance is shared between requests in a test).
 */
function http()
{
    app('auth')->forgetGuards();

    return test();
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $variants
 */
function makeProduct(Tenant $tenant, array $attributes = [], array $variants = []): Product
{
    $product = Product::factory()->forTenant($tenant)->create($attributes);

    foreach ($variants as $i => $variant) {
        ProductVariant::factory()->forProduct($product)->create($variant + ['position' => $i]);
    }

    return app(TenantContext::class)->runAs($tenant, fn () => $product->refresh()->load('variants'));
}

/**
 * Create a cart holding the given lines: [[Product, quantity, ?ProductVariant], ...]
 *
 * @param  list<array{0: Product, 1: int, 2?: ?ProductVariant}>  $lines
 */
function makeCart(Tenant $tenant, array $lines, ?Customer $customer = null): Cart
{
    return app(TenantContext::class)->runAs($tenant, function () use ($lines, $customer) {
        $cart = app(CartService::class)->create($customer);

        foreach ($lines as $line) {
            $cart->items()->create(['product_id' => $line[0]->getKey(), 'variant_id' => ($line[2] ?? null)?->getKey(), 'quantity' => $line[1]]);
        }

        return $cart;
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutPayload(Cart $cart, array $overrides = []): array
{
    return array_replace_recursive([
        'cart_id' => $cart->id,
        'customer' => ['email' => 'Buyer@Example.com', 'first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '+2348000000000'],
        'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'line1' => '1 Marina', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG'],
    ], $overrides);
}

/**
 * Place an order for a cart through the public checkout endpoint.
 *
 * @param  array<string, mixed>  $overrides
 */
function checkout(Tenant $tenant, Cart $cart, array $overrides = [], ?string $token = null, string $key = 'idem-key-00000001'): TestResponse
{
    return http()->postJson('/api/v1/storefront/checkout', checkoutPayload($cart, $overrides), storefrontHeaders($tenant, $token, $key));
}

/*
|--------------------------------------------------------------------------
| Payment helpers
|--------------------------------------------------------------------------
*/

function enableGateway(Tenant $tenant, PaymentProviderType $type = PaymentProviderType::Paystack, array $attributes = []): PaymentGateway
{
    return app(TenantContext::class)->runAs($tenant, function () use ($type, $attributes) {
        $gateway = new PaymentGateway($attributes + [
            'secret_key' => $type === PaymentProviderType::Paystack ? 'sk_test_abc123' : 'FLWSECK_TEST-abc123',
            'webhook_secret' => $type === PaymentProviderType::Flutterwave ? 'flw-hash-secret' : null,
            'is_active' => true,
        ]);
        $gateway->provider = $type;
        $gateway->save();

        return $gateway;
    });
}

/**
 * Place an order through checkout and return it (tenant-scoped lookup).
 */
function placedOrder(Tenant $tenant, ?Product $product = null, int $qty = 1, string $key = 'ord-key-0000001', array $overrides = []): Order
{
    $product ??= Product::factory()->forTenant($tenant)->create(['price' => 150000, 'stock' => 20]);
    $id = checkout($tenant, makeCart($tenant, [[$product, $qty]]), $overrides, key: $key)->assertCreated()->json('data.id');

    return Order::withoutTenantScope()->where('id', $id)->firstOrFail();
}

function initPayment(Tenant $tenant, Order $order, array $body = [], string $key = 'pay-key-0000001', ?string $token = null): TestResponse
{
    return http()->postJson('/api/v1/storefront/payments/initialize', $body + ['order_id' => $order->id, 'email' => $order->customer_email], storefrontHeaders($tenant, $token, $key));
}

/**
 * Http::fake() stubs stack with first-match-wins; this replaces them instead.
 *
 * @param  array<string, mixed>  $stubs
 */
function refakeHttp(array $stubs = []): void
{
    Http::swap(new Factory);
    Http::fake($stubs);
}

function fakePaystackInit(string $url = 'https://checkout.paystack.com/abc'): void
{
    refakeHttp([
        'api.paystack.co/transaction/initialize' => fn ($request) => Http::response(['status' => true, 'data' => ['authorization_url' => $url, 'access_code' => 'ac', 'reference' => $request['reference']]]),
    ]);
}

function paystackVerifyResponse(string $reference, int $amount, string $status = 'success', string $currency = 'NGN', int $id = 987): Response|PromiseInterface
{
    return Http::response(['status' => true, 'data' => ['id' => $id, 'status' => $status, 'reference' => $reference, 'amount' => $amount, 'currency' => $currency, 'channel' => 'card', 'gateway_response' => $status === 'success' ? 'Successful' : 'Declined']]);
}

function paystackWebhook(Tenant $tenant, string $reference, string $secret = 'sk_test_abc123', int $eventId = 111, string $event = 'charge.success'): TestResponse
{
    $body = json_encode(['event' => $event, 'data' => ['id' => $eventId, 'reference' => $reference, 'status' => 'success']]);

    return http()->call('POST', "/api/v1/webhooks/paystack/{$tenant->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
    ], $body);
}
