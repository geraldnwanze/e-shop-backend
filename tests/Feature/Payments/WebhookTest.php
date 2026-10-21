<?php

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Notifications\OrderNotification;
use App\Notifications\StoreMessageNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    enableGateway($this->a['tenant']);
    enableGateway($this->b['tenant'], attributes: ['secret_key' => 'sk_test_bbb222']);
    fakePaystackInit();

    $this->order = placedOrder($this->a['tenant'], qty: 2); // 300000
    $this->reference = initPayment($this->a['tenant'], $this->order)->json('data.payment.reference');
});

function receiptsSent(): int
{
    $count = 0;

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass[StoreMessageNotification::class] ?? [] as $row) {
            $count += $row['notification']->kind === 'payment_receipt' ? 1 : 0;
        }
    }

    return $count;
}

function orderState(Order $order): array
{
    $o = Order::withoutTenantScope()->find($order->id);

    return [$o->status->value, $o->payment_status->value];
}

it('confirms payment only after verifying with the provider, then moves the order to PROCESSING', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);

    paystackWebhook($this->a['tenant'], $this->reference)->assertOk()->assertJsonPath('data.received', true);

    expect(orderState($this->order))->toBe(['PROCESSING', 'PAID']);
    $payment = Payment::withoutTenantScope()->where('reference', $this->reference)->first();
    expect($payment->status)->toBe(PaymentStatus::Paid)->and($payment->provider_reference)->toBe('987')->and($payment->paid_at)->not->toBeNull()
        ->and($payment->meta)->toBe(['channel' => 'card', 'gateway_response' => 'Successful']);
    Http::assertSent(fn ($r) => str_contains($r->url(), "transaction/verify/{$this->reference}"));
    // Confirmation only: the payment receipt already tells the customer the order is being prepared.
    Notification::assertSentOnDemandTimes(OrderNotification::class, 1);
    Notification::assertSentOnDemand(StoreMessageNotification::class, fn (StoreMessageNotification $n, array $channels, $notifiable) => $n->kind === 'payment_receipt' && $notifiable->routes['mail'] === 'buyer@example.com');
});

it('rejects webhooks with a missing or wrong signature and changes nothing', function () {
    refakeHttp();
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $this->reference]]);

    $this->call('POST', "/api/v1/webhooks/paystack/{$this->a['tenant']->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertUnauthorized()->assertJsonPath('meta.errorCode', 'INVALID_WEBHOOK_SIGNATURE');
    paystackWebhook($this->a['tenant'], $this->reference, secret: 'sk_test_wrong')->assertUnauthorized();

    expect(orderState($this->order))->toBe(['PENDING', 'PENDING'])->and(WebhookEvent::count())->toBe(0);
    Http::assertNothingSent();
});

it('does not trust the webhook body: provider says abandoned means not paid', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000, 'abandoned')]);

    paystackWebhook($this->a['tenant'], $this->reference)->assertOk();

    expect(orderState($this->order))->toBe(['PENDING', 'PENDING'])
        ->and(Payment::withoutTenantScope()->first()->status)->toBe(PaymentStatus::Pending);
});

it('refuses to mark an order paid when amount or currency differ', function (int $amount, string $currency) {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, $amount, 'success', $currency)]);

    paystackWebhook($this->a['tenant'], $this->reference)->assertOk();

    expect(orderState($this->order))->toBe(['PENDING', 'PENDING']);
    $payment = Payment::withoutTenantScope()->first();
    expect($payment->status)->toBe(PaymentStatus::Failed)->and($payment->failure_reason)->toBe('amount_mismatch');
})->with([[100, 'NGN'], [300000, 'USD'], [999999, 'NGN']]);

it('records a declined payment and allows another attempt', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000, 'failed')]);

    paystackWebhook($this->a['tenant'], $this->reference)->assertOk();
    expect(orderState($this->order))->toBe(['PENDING', 'FAILED']);

    fakePaystackInit('https://checkout.paystack.com/second');
    initPayment($this->a['tenant'], Order::withoutTenantScope()->find($this->order->id), key: 'pay-key-0000009')->assertOk()
        ->assertJsonPath('data.payment.authorizationUrl', 'https://checkout.paystack.com/second');
});

it('is idempotent: duplicate deliveries are acknowledged but processed once', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);

    paystackWebhook($this->a['tenant'], $this->reference, eventId: 500)->assertOk()->assertJsonPath('data.received', true);
    paystackWebhook($this->a['tenant'], $this->reference, eventId: 500)->assertOk()->assertJsonPath('data.duplicate', true);

    expect(WebhookEvent::count())->toBe(1);
    Http::assertSentCount(1);
    Notification::assertSentOnDemandTimes(OrderNotification::class, 1);
    expect(receiptsSent())->toBe(1);
});

it('is idempotent even when a different event id arrives for an already-paid payment', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);

    paystackWebhook($this->a['tenant'], $this->reference, eventId: 1)->assertOk();
    paystackWebhook($this->a['tenant'], $this->reference, eventId: 2)->assertOk();

    expect(orderState($this->order))->toBe(['PROCESSING', 'PAID']);
    Notification::assertSentOnDemandTimes(OrderNotification::class, 1);
    expect(receiptsSent())->toBe(1); // a duplicate delivery never sends a second receipt
});

it('ignores unsupported events without side effects', function () {
    refakeHttp();

    paystackWebhook($this->a['tenant'], $this->reference, event: 'transfer.success')->assertOk()->assertJsonPath('data.ignored', true);

    Http::assertNothingSent();
    expect(WebhookEvent::count())->toBe(0);
});

it('acknowledges but ignores unknown references', function () {
    refakeHttp();

    paystackWebhook($this->a['tenant'], 'pay_doesnotexist')->assertOk();

    expect(WebhookEvent::first()->status->value)->toBe('ignored');
    Http::assertNothingSent();
});

it('cannot be used to confirm another tenant\'s payment', function () {
    refakeHttp(['api.paystack.co/*' => paystackVerifyResponse($this->reference, 300000)]);

    // B signs with its own valid secret but names A's payment reference.
    paystackWebhook($this->b['tenant'], $this->reference, secret: 'sk_test_bbb222')->assertOk();
    expect(orderState($this->order))->toBe(['PENDING', 'PENDING'])->and(WebhookEvent::first()->status->value)->toBe('ignored');

    // A's URL signed with B's secret is rejected outright.
    paystackWebhook($this->a['tenant'], $this->reference, secret: 'sk_test_bbb222')->assertUnauthorized();
    // B's URL signed with A's secret is rejected too.
    paystackWebhook($this->b['tenant'], $this->reference, secret: 'sk_test_abc123', eventId: 9)->assertUnauthorized();
});

it('rejects webhooks for tenants without that gateway or that are suspended', function () {
    $c = createMerchant('nogateway');
    paystackWebhook($c['tenant'], $this->reference)->assertUnauthorized();

    $this->a['tenant']->update(['status' => 'suspended']);
    paystackWebhook($this->a['tenant'], $this->reference)->assertUnauthorized();
    paystackWebhook($this->a['tenant'], $this->reference)->assertStatus(401);
});

it('returns 404 for unknown tenants and providers', function () {
    $this->call('POST', '/api/v1/webhooks/paystack/'.fake()->uuid(), [], [], [], ['HTTP_ACCEPT' => 'application/json'], '{}')->assertNotFound();
    $this->call('POST', "/api/v1/webhooks/stripe/{$this->a['tenant']->id}", [], [], [], ['HTTP_ACCEPT' => 'application/json'], '{}')->assertNotFound();
});

it('verifies Flutterwave webhooks with the store\'s hash secret', function () {
    enableGateway($this->a['tenant'], PaymentProviderType::Flutterwave);
    refakeHttp([
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flw.test/pay']]),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => fn ($r) => Http::response(['status' => 'success', 'data' => ['id' => 42, 'tx_ref' => $r['tx_ref'], 'status' => 'successful', 'amount' => 3000.0, 'currency' => 'NGN']]),
    ]);
    $order = placedOrder($this->a['tenant'], qty: 2, key: 'ord-key-flw0001');
    $ref = initPayment($this->a['tenant'], $order, ['provider' => 'flutterwave'], 'pay-key-flw00001')->assertOk()->json('data.payment.reference');
    $body = json_encode(['event' => 'charge.completed', 'data' => ['id' => 42, 'tx_ref' => $ref, 'status' => 'successful']]);
    $url = "/api/v1/webhooks/flutterwave/{$this->a['tenant']->id}";

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_VERIF_HASH' => 'wrong'], $body)->assertUnauthorized();
    expect(orderState($order))->toBe(['PENDING', 'PENDING']);

    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_VERIF_HASH' => 'flw-hash-secret'], $body)->assertOk();
    expect(orderState($order))->toBe(['PROCESSING', 'PAID']);
});

it('confirms via the return-URL verify endpoint using the provider, not the redirect', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000, 'abandoned')]);
    $h = storefrontHeaders($this->a['tenant']);

    http()->getJson("/api/v1/storefront/payments/{$this->reference}/verify", $h)->assertOk()->assertJsonPath('data.paymentStatus', 'PENDING');

    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);
    http()->getJson("/api/v1/storefront/payments/{$this->reference}/verify", $h)->assertOk()->assertJsonPath('data.paymentStatus', 'PAID')->assertJsonPath('data.orderStatus', 'PROCESSING');
    http()->getJson("/api/v1/storefront/payments/{$this->reference}/verify", $h)->assertOk()->assertJsonPath('data.paymentStatus', 'PAID');

    Notification::assertSentOnDemandTimes(OrderNotification::class, 1);
    expect(receiptsSent())->toBe(1);
    http()->getJson("/api/v1/storefront/payments/{$this->reference}/verify", storefrontHeaders($this->b['tenant']))->assertNotFound();
    http()->getJson('/api/v1/storefront/payments/pay_nothing/verify', $h)->assertNotFound();
});

it('keeps a paid order paid if a later verification reports otherwise', function () {
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);
    paystackWebhook($this->a['tenant'], $this->reference)->assertOk();

    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000, 'failed')]);
    http()->getJson("/api/v1/storefront/payments/{$this->reference}/verify", storefrontHeaders($this->a['tenant']))->assertOk();

    expect(orderState($this->order))->toBe(['PROCESSING', 'PAID']);
});

it('flags a payment that arrives for an already cancelled order without un-cancelling it', function () {
    Order::withoutTenantScope()->whereKey($this->order->id)->update(['status' => 'CANCELLED']);
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($this->reference, 300000)]);

    paystackWebhook($this->a['tenant'], $this->reference)->assertOk();

    expect(orderState($this->order))->toBe(['CANCELLED', 'PAID']);
});
