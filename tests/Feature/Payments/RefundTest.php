<?php

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    enableGateway($this->a['tenant']);
    $this->order = placedOrder($this->a['tenant'], qty: 2); // 300000
    Order::withoutTenantScope()->whereKey($this->order->id)->update(['payment_status' => 'PAID', 'status' => 'PROCESSING']);
    $this->payment = Payment::factory()->forOrder($this->order)->paid()->create(['reference' => 'pay_refundtest']);
    refakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55]])]);
    $this->actingAs($this->a['owner'], 'sanctum');
});

function refund(array $body = [], string $key = 'refund-key-0001', ?Order $order = null)
{
    $order ??= test()->order;

    return test()->postJson("/api/v1/orders/{$order->id}/refund", $body, ['Idempotency-Key' => $key]);
}

it('issues a partial refund then a final one', function () {
    refund(['amount' => 100000, 'reason' => 'Damaged'], 'refund-key-0001')->assertCreated()
        ->assertJsonPath('data.amount', 100000)->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.order.paymentStatus', 'PARTIALLY_REFUNDED')->assertJsonPath('data.order.payments.0.refundedAmount', 100000);
    Http::assertSent(fn ($r) => $r['transaction'] === 'pay_refundtest' && $r['amount'] === 100000 && $r->hasHeader('Authorization', 'Bearer sk_test_abc123'));

    refund([], 'refund-key-0002')->assertCreated()->assertJsonPath('data.amount', 200000)->assertJsonPath('data.order.paymentStatus', 'REFUNDED');

    expect($this->payment->fresh()->status)->toBe(PaymentStatus::Refunded)->and($this->payment->fresh()->refunded_amount)->toBe(300000);
    refund([], 'refund-key-0003')->assertStatus(409);
});

it('refuses refunds above the remaining amount', function (int $amount) {
    refund(['amount' => 100000], 'refund-key-0001')->assertCreated();

    refund(['amount' => $amount], 'refund-key-0002')->assertStatus(409);
    expect(Refund::withoutTenantScope()->count())->toBe(1);
})->with([200001, 300000]);

it('validates the refund payload', function () {
    refund(['amount' => 0])->assertUnprocessable();
    refund(['amount' => -5], 'refund-key-0002')->assertUnprocessable();
    refund(['amount' => 12.5], 'refund-key-0003')->assertUnprocessable();
});

it('releases the reserved amount when the provider rejects the refund', function () {
    refakeHttp(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'no'], 400)]);

    refund(['amount' => 100000])->assertStatus(502);

    expect(Refund::withoutTenantScope()->first()->status->value)->toBe('failed')
        ->and($this->payment->fresh()->refundableAmount())->toBe(300000)
        ->and($this->payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(Order::withoutTenantScope()->find($this->order->id)->payment_status)->toBe(PaymentStatus::Paid);

    refakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 56]])]);
    refund(['amount' => 300000], 'refund-key-0002')->assertCreated();
});

it('is idempotent and never refunds twice for a retried request', function () {
    refund(['amount' => 100000], 'same-key-000001')->assertCreated();
    refund(['amount' => 100000], 'same-key-000001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Refund::withoutTenantScope()->count())->toBe(1);
    Http::assertSentCount(1);
    refund(['amount' => 5], 'same-key-000001')->assertStatus(422)->assertJsonPath('meta.errorCode', 'IDEMPOTENCY_KEY_REUSED');
});

it('requires an Idempotency-Key header', function () {
    $this->postJson("/api/v1/orders/{$this->order->id}/refund", [])->assertUnprocessable();
});

it('cannot refund unpaid orders', function () {
    $unpaid = placedOrder($this->a['tenant'], key: 'ord-key-unpaid01');
    $this->actingAs($this->a['owner'], 'sanctum');

    refund([], 'refund-key-0009', $unpaid)->assertStatus(409);
});

it('limits refunds to owners and admins', function () {
    foreach ([[UserRole::MerchantStaff, 403], [UserRole::Customer, 403], [UserRole::MerchantAdmin, 201]] as $i => [$role, $status]) {
        $user = User::factory()->forTenant($this->a['tenant'], $role)->create();
        $this->actingAs($user, 'sanctum');
        refund(['amount' => 1000], "role-key-0000{$i}")->assertStatus($status);
    }
});

it('cannot refund another tenant\'s order', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    refund([], 'refund-key-0001')->assertNotFound();
    expect(Refund::withoutTenantScope()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refunds Flutterwave payments by transaction id in major units', function () {
    enableGateway($this->a['tenant'], PaymentProviderType::Flutterwave);
    $this->payment->update(['provider' => PaymentProviderType::Flutterwave, 'provider_reference' => '42']);
    refakeHttp(['api.flutterwave.com/*' => Http::response(['status' => 'success', 'data' => ['id' => 9]])]);

    refund(['amount' => 150050])->assertCreated();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transactions/42/refund') && $r['amount'] === '1500.50');
});

it('shows payments on the merchant order view without secrets', function () {
    $json = $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk()->assertJsonPath('data.payments.0.status', 'PAID');

    expect($json->getContent())->not->toContain('sk_test')->not->toContain('authorizationUrl');
});
