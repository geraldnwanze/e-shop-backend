<?php

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use App\Services\Payments\FlutterwaveProvider;
use App\Services\Payments\InitializedPayment;
use App\Services\Payments\InitializeRequest;
use App\Services\Payments\PaymentCredentials;
use App\Services\Payments\PaymentManager;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\PaystackProvider;
use App\Services\Payments\RefundResult;
use App\Services\Payments\VerifiedPayment;
use App\Services\Payments\WebhookNotification;
use Illuminate\Support\Facades\Http;

function initRequest(int $amount = 150000): InitializeRequest
{
    return new InitializeRequest('ref_123', $amount, 'NGN', 'a@b.com', 'Ada Obi', 'https://shop.test/cb', ['order_number' => 'ORD-1']);
}

describe('Paystack', function () {
    beforeEach(fn () => $this->p = new PaystackProvider(new PaymentCredentials('sk_test_abc123')));

    it('initializes in minor units with bearer auth', function () {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://pay.test/x', 'reference' => 'ref_123']])]);

        $result = $this->p->initialize(initRequest());

        expect($result)->toBeInstanceOf(InitializedPayment::class)->and($result->authorizationUrl)->toBe('https://pay.test/x');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.paystack.co/transaction/initialize'
            && $r->hasHeader('Authorization', 'Bearer sk_test_abc123')
            && $r['amount'] === 150000 && $r['currency'] === 'NGN' && $r['reference'] === 'ref_123' && $r['callback_url'] === 'https://shop.test/cb');
    });

    it('maps verify statuses', function (string $status, PaymentStatus $expected) {
        Http::fake(['api.paystack.co/*' => paystackVerifyResponse('ref_123', 150000, $status)]);

        $v = $this->p->verify('ref_123');

        expect($v->status)->toBe($expected)->and($v->amount)->toBe(150000)->and($v->currency)->toBe('NGN')->and($v->providerReference)->toBe('987');
    })->with([['success', PaymentStatus::Paid], ['failed', PaymentStatus::Failed], ['reversed', PaymentStatus::Failed], ['abandoned', PaymentStatus::Pending], ['ongoing', PaymentStatus::Pending]]);

    it('throws a payment exception when the provider errors', function () {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        expect(fn () => $this->p->initialize(initRequest()))->toThrow(PaymentException::class);
    });

    it('verifies webhook signatures with HMAC-SHA512', function () {
        $body = '{"event":"charge.success"}';
        $sig = hash_hmac('sha512', $body, 'sk_test_abc123');

        expect($this->p->verifyWebhookSignature($body, ['x-paystack-signature' => $sig]))->toBeTrue()
            ->and($this->p->verifyWebhookSignature($body.' ', ['x-paystack-signature' => $sig]))->toBeFalse()
            ->and($this->p->verifyWebhookSignature($body, ['x-paystack-signature' => 'nope']))->toBeFalse()
            ->and($this->p->verifyWebhookSignature($body, []))->toBeFalse();
    });

    it('parses only charge.success webhooks', function () {
        $n = $this->p->parseWebhook('{"event":"charge.success","data":{"id":5,"reference":"r1"}}');

        expect($n)->toBeInstanceOf(WebhookNotification::class)->and($n->reference)->toBe('r1')->and($n->eventId)->toBe('charge.success:5')
            ->and($this->p->parseWebhook('{"event":"transfer.success","data":{}}'))->toBeNull()
            ->and($this->p->parseWebhook('garbage'))->toBeNull();
    });

    it('refunds by reference in minor units', function () {
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55]])]);

        $result = $this->p->refund((new Payment)->forceFill(['reference' => 'ref_9']), 50000, 'oops');

        expect($result->providerReference)->toBe('55');
        Http::assertSent(fn ($r) => $r['transaction'] === 'ref_9' && $r['amount'] === 50000);
    });
});

describe('Flutterwave', function () {
    beforeEach(fn () => $this->p = new FlutterwaveProvider(new PaymentCredentials('FLWSECK_TEST-abc', 'flw-hash-secret')));

    it('sends major units to the API', function () {
        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['link' => 'https://flw.test/pay']])]);

        $result = $this->p->initialize(initRequest(150050));

        expect($result->authorizationUrl)->toBe('https://flw.test/pay');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.flutterwave.com/v3/payments' && $r['amount'] === '1500.50' && $r['tx_ref'] === 'ref_123' && $r['redirect_url'] === 'https://shop.test/cb');
    });

    it('converts verified amounts back to minor units', function () {
        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['id' => 42, 'tx_ref' => 'ref_123', 'status' => 'successful', 'amount' => 1500.5, 'currency' => 'ngn']])]);

        $v = $this->p->verify('ref_123');

        expect($v->status)->toBe(PaymentStatus::Paid)->and($v->amount)->toBe(150050)->and($v->currency)->toBe('NGN')->and($v->providerReference)->toBe('42');
    });

    it('authenticates webhooks with the verif-hash secret', function () {
        expect($this->p->verifyWebhookSignature('{}', ['verif-hash' => 'flw-hash-secret']))->toBeTrue()
            ->and($this->p->verifyWebhookSignature('{}', ['verif-hash' => 'wrong']))->toBeFalse()
            ->and($this->p->verifyWebhookSignature('{}', []))->toBeFalse()
            ->and((new FlutterwaveProvider(new PaymentCredentials('k', null)))->verifyWebhookSignature('{}', ['verif-hash' => '']))->toBeFalse();
    });

    it('refunds by provider transaction id in major units', function () {
        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['id' => 9]])]);

        $this->p->refund((new Payment)->forceFill(['provider_reference' => '42']), 50000);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transactions/42/refund') && $r['amount'] === '500.00');
    });

    it('parses charge.completed webhooks', function () {
        expect($this->p->parseWebhook('{"event":"charge.completed","data":{"id":7,"tx_ref":"r1"}}')->reference)->toBe('r1');
    });
});

it('lets the application use any provider through the abstraction alone', function () {
    $fake = new class implements PaymentProvider
    {
        public function type(): PaymentProviderType
        {
            return PaymentProviderType::Paystack;
        }

        public function initialize(InitializeRequest $request): InitializedPayment
        {
            return new InitializedPayment($request->reference, 'https://fake.test/'.$request->reference);
        }

        public function verify(string $reference): VerifiedPayment
        {
            return new VerifiedPayment($reference, PaymentStatus::Paid, 150000, 'NGN', 'fake-1');
        }

        public function verifyWebhookSignature(string $payload, array $headers): bool
        {
            return true;
        }

        public function parseWebhook(string $payload): ?WebhookNotification
        {
            return null;
        }

        public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
        {
            return new RefundResult('fake-refund');
        }
    };
    app(PaymentManager::class)->extend(PaymentProviderType::Paystack, fn () => $fake);
    $m = createMerchant('icecream');
    enableGateway($m['tenant']);
    refakeHttp(); // no HTTP must be needed
    $order = placedOrder($m['tenant']);

    $payment = initPayment($m['tenant'], $order)->assertOk()->json('data.payment');
    expect($payment['authorizationUrl'])->toStartWith('https://fake.test/');

    http()->getJson("/api/v1/storefront/payments/{$payment['reference']}/verify", storefrontHeaders($m['tenant']))->assertOk()->assertJsonPath('data.paymentStatus', 'PAID');
    Http::assertNothingSent();
});
