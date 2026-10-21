<?php

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    enableGateway($this->a['tenant']);
    fakePaystackInit();
});

it('initializes a payment using server-side amount, currency and callback', function () {
    $order = placedOrder($this->a['tenant'], qty: 2);

    $response = initPayment($this->a['tenant'], $order, ['amount' => 1, 'currency' => 'USD', 'callback_url' => 'https://evil.test/steal'])
        ->assertOk()->assertJsonPath('data.requiresPayment', true)
        ->assertJsonPath('data.payment.status', 'PENDING')->assertJsonPath('data.payment.amount', 300000)
        ->assertJsonPath('data.payment.authorizationUrl', 'https://checkout.paystack.com/abc');

    $payment = Payment::withoutTenantScope()->where('id', $response->json('data.payment.id'))->firstOrFail();
    expect($payment->tenant_id)->toBe($this->a['tenant']->id)->and($payment->amount)->toBe(300000)->and($payment->currency)->toBe('NGN')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'transaction/initialize')
        && $r['amount'] === 300000 && $r['currency'] === 'NGN' && $r['email'] === 'buyer@example.com'
        && str_starts_with($r['callback_url'], 'https://icecream.mydomain.test/checkout/payment?reference=pay_'));
    expect(Order::withoutTenantScope()->find($order->id)->payment_status)->toBe(PaymentStatus::Pending);
});

it('reuses a recent pending payment instead of creating another', function () {
    $order = placedOrder($this->a['tenant']);

    $first = initPayment($this->a['tenant'], $order, key: 'pay-key-0000001')->json('data.payment.id');
    $second = initPayment($this->a['tenant'], $order, key: 'pay-key-0000002')->json('data.payment.id');

    expect($second)->toBe($first)->and(Payment::withoutTenantScope()->count())->toBe(1);
    Http::assertSentCount(1);

    $this->travel(31)->minutes();
    $third = initPayment($this->a['tenant'], $order, key: 'pay-key-0000003')->json('data.payment.id');
    expect($third)->not->toBe($first);
});

it('replays the same response for a retried request with the same key', function () {
    $order = placedOrder($this->a['tenant']);

    $one = initPayment($this->a['tenant'], $order)->assertOk();
    $two = initPayment($this->a['tenant'], $order)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($two->json('data.payment.id'))->toBe($one->json('data.payment.id'));
    Http::assertSentCount(1);
});

it('requires an Idempotency-Key', function () {
    $order = placedOrder($this->a['tenant']);

    http()->postJson('/api/v1/storefront/payments/initialize', ['order_id' => $order->id, 'email' => $order->customer_email], storefrontHeaders($this->a['tenant']))->assertUnprocessable();
});

it('only lets the order\'s owner initialize payment', function () {
    $order = placedOrder($this->a['tenant']);

    initPayment($this->a['tenant'], $order, ['email' => 'wrong@example.com'], 'k-wrong-0000001')->assertNotFound();
    http()->postJson('/api/v1/storefront/payments/initialize', ['order_id' => $order->id], storefrontHeaders($this->a['tenant'], idempotencyKey: 'k-noemail-00001'))->assertNotFound();
    initPayment($this->b['tenant'], $order, [], 'k-othertenant-01')->assertNotFound();
    expect(Payment::withoutTenantScope()->count())->toBe(0);
});

it('requires the signed-in customer for orders placed while signed in', function () {
    $me = Customer::factory()->forTenant($this->a['tenant'])->create();
    $other = Customer::factory()->forTenant($this->a['tenant'])->create();
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 5]);
    $token = $me->createToken('t')->plainTextToken;
    $id = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]], $me), token: $token)->json('data.id');
    $order = Order::withoutTenantScope()->where('id', $id)->firstOrFail();

    // knowing the email is not enough for an account order
    initPayment($this->a['tenant'], $order, [], 'k-guest-00000001')->assertNotFound();
    initPayment($this->a['tenant'], $order, [], 'k-other-00000001', $other->createToken('t')->plainTextToken)->assertNotFound();
    initPayment($this->a['tenant'], $order, [], 'k-me-0000000001', $token)->assertOk();
});

it('refuses paid, cancelled and non-pending orders', function () {
    $paid = placedOrder($this->a['tenant'], key: 'ord-key-paid0001');
    Order::withoutTenantScope()->whereKey($paid->id)->update(['payment_status' => 'PAID']);
    initPayment($this->a['tenant'], $paid, [], 'k-paid-000000001')->assertStatus(409);

    $cancelled = placedOrder($this->a['tenant'], key: 'ord-key-canc0001');
    Order::withoutTenantScope()->whereKey($cancelled->id)->update(['status' => 'CANCELLED']);
    initPayment($this->a['tenant'], $cancelled, [], 'k-canc-000000001')->assertStatus(409);
});

it('fails clearly when the store has no payment method enabled', function () {
    $order = placedOrder($this->b['tenant'], Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5]));

    initPayment($this->b['tenant'], $order)->assertStatus(422)->assertJsonPath('meta.errorCode', 'PAYMENT_PROVIDER_ERROR');
});

it('rejects a provider the store has not enabled and honours the default', function () {
    $order = placedOrder($this->a['tenant']);

    initPayment($this->a['tenant'], $order, ['provider' => 'flutterwave'], 'k-flw-0000000001')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['provider']]]);
    initPayment($this->a['tenant'], $order, ['provider' => 'stripe'], 'k-bad-0000000001')->assertUnprocessable();

    enableGateway($this->a['tenant'], PaymentProviderType::Flutterwave, ['is_default' => true]);
    refakeHttp(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flw.test/pay']])]);
    initPayment($this->a['tenant'], $order, [], 'k-def-0000000001')->assertOk()->assertJsonPath('data.payment.provider', 'flutterwave')
        ->assertJsonPath('data.payment.authorizationUrl', 'https://flw.test/pay');
});

it('uses platform credentials only when explicitly enabled', function () {
    $order = placedOrder($this->b['tenant'], Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5]));
    config(['payments.providers.paystack.secret_key' => 'sk_test_platform1']);

    initPayment($this->b['tenant'], $order, [], 'k-plat-0000000001')->assertStatus(422);

    config(['payments.use_platform_credentials' => true]);
    initPayment($this->b['tenant'], $order, [], 'k-plat-0000000002')->assertOk();
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk_test_platform1'));
});

it('does not use an inactive store gateway even if platform credentials exist', function () {
    enableGateway($this->b['tenant'], attributes: ['is_active' => false]);
    config(['payments.use_platform_credentials' => true, 'payments.providers.paystack.secret_key' => 'sk_test_platform1']);
    $order = placedOrder($this->b['tenant'], Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5]));

    initPayment($this->b['tenant'], $order)->assertStatus(422);
});

it('marks the attempt failed and returns 502 when the provider is down', function () {
    refakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'boom'], 500)]);
    $order = placedOrder($this->a['tenant']);

    initPayment($this->a['tenant'], $order)->assertStatus(502)->assertJsonPath('meta.errorCode', 'PAYMENT_PROVIDER_ERROR');

    expect(Payment::withoutTenantScope()->first()->status)->toBe(PaymentStatus::Failed);
    // the key is released and a later retry works
    fakePaystackInit();
    initPayment($this->a['tenant'], $order)->assertOk();
});

it('settles zero-total orders without a provider', function () {
    Discount::factory()->forTenant($this->a['tenant'])->fixed(99999999)->create(['code' => 'FREE']);
    $order = placedOrder($this->a['tenant'], overrides: ['discount_code' => 'FREE']);
    expect($order->total)->toBe(0);

    initPayment($this->a['tenant'], $order)->assertOk()->assertJsonPath('data.requiresPayment', false)->assertJsonPath('data.payment', null);

    $fresh = Order::withoutTenantScope()->find($order->id);
    expect($fresh->payment_status)->toBe(PaymentStatus::Paid)->and($fresh->status->value)->toBe('PROCESSING')->and(Payment::withoutTenantScope()->count())->toBe(0);
    Http::assertNothingSent();
});

it('never leaks provider credentials in the response', function () {
    $order = placedOrder($this->a['tenant']);

    $body = initPayment($this->a['tenant'], $order)->getContent();

    expect($body)->not->toContain('sk_test_abc123')->not->toContain('access_code');
});
