<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ThemeConfiguration;
use App\Notifications\OrderNotification;
use App\Notifications\StoreMessageNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    enableGateway($this->a['tenant']);
    fakePaystackInit();
});

/**
 * Store emails sent to an address, as [notification, address] pairs.
 *
 * @return list<array{0: OrderNotification|StoreMessageNotification, 1: string}>
 */
function emailsSent(?string $kind = null): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                $notification = $row['notification'];
                $matches = $kind === null || ($notification instanceof StoreMessageNotification && $notification->kind === $kind) || ($kind === 'order' && $notification instanceof OrderNotification);

                if ($matches) {
                    $sent[] = [$notification, $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function setNotifications(array $notifications): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => $notifications])->assertOk();
    app('auth')->forgetGuards();
    test()->actingAs(test()->a['owner'], 'sanctum');
}

function payOrder(Order $order, string $reference): void
{
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($reference, $order->total)]);
    paystackWebhook(test()->a['tenant'], $reference)->assertOk();
    fakePaystackInit();
}

function orderAwaitingPayment(): array
{
    $order = placedOrder(test()->a['tenant'], qty: 2);
    $reference = initPayment(test()->a['tenant'], $order)->json('data.payment.reference');

    return [$order, $reference];
}

it('sends a branded order confirmation to the customer', function () {
    placedOrder($this->a['tenant']);

    [$emails] = [emailsSent('order')];
    expect($emails)->toHaveCount(1)->and($emails[0][1])->toBe('buyer@example.com');

    $html = (string) $emails[0][0]->toMail(new AnonymousNotifiable)->render();

    expect($html)->toContain('Icecream')->toContain('Order confirmation')->toContain('ORD-')->toContain('Track your order')->toContain('/orders/track');
});

it('uses the store theme colour in the email', function () {
    ThemeConfiguration::factory()->create(['store_id' => $this->a['store']->id, 'config' => ['colors' => ['primary' => '#d6249f']]]);

    placedOrder($this->a['tenant']);

    expect((string) emailsSent('order')[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('#d6249f');
});

it('skips the order confirmation when the store turned it off', function () {
    setNotifications(['customer_order_confirmation' => false]);

    placedOrder($this->a['tenant']);

    expect(emailsSent('order'))->toBeEmpty();
});

it('emails the customer when an order ships, unless updates are off', function () {
    $order = placedOrder($this->a['tenant']);
    Order::withoutTenantScope()->whereKey($order->id)->update(['status' => 'PROCESSING', 'payment_status' => 'PAID']);

    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'SHIPPED'])->assertOk();

    $sent = emailsSent('order');
    expect($sent)->toHaveCount(2)->and($sent[1][0]->subject)->toBe('Your order has shipped');

    setNotifications(['customer_order_updates' => false]);
    $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'DELIVERED'])->assertOk();

    expect(emailsSent('order'))->toHaveCount(2);
});

it('does not send a separate processing email for a paid order but does for an unpaid one', function () {
    [$paid, $reference] = orderAwaitingPayment();
    payOrder($paid, $reference);

    expect(array_map(fn ($e) => $e[0]->subject, emailsSent('order')))->toBe(['Order confirmation']);

    $unpaid = placedOrder($this->a['tenant'], key: 'ord-key-0000002');
    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/orders/{$unpaid->id}/status", ['status' => 'PROCESSING'])->assertOk();

    expect(array_map(fn ($e) => $e[0]->subject, emailsSent('order')))->toContain('Your order is being prepared');
});

it('sends the customer a receipt and the merchant a new-order alert when a payment is confirmed', function () {
    [$order, $reference] = orderAwaitingPayment();
    payOrder($order, $reference);

    $receipt = emailsSent('payment_receipt');
    expect($receipt)->toHaveCount(1)->and($receipt[0][1])->toBe('buyer@example.com');
    expect((string) $receipt[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('Payment received')->toContain('3,000.00 NGN')->toContain($reference);

    $alert = emailsSent('new_order');
    expect($alert)->toHaveCount(1)->and($alert[0][1])->toBe($this->a['owner']->email);
    expect((string) $alert[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('You have a new order')->toContain("/dashboard/orders/{$order->id}");
});

it('sends merchant alerts to the chosen address and honours the switches', function () {
    setNotifications(['merchant_email' => 'orders@icecream.example']);
    [$order, $reference] = orderAwaitingPayment();
    payOrder($order, $reference);

    expect(emailsSent('new_order')[0][1])->toBe('orders@icecream.example');

    setNotifications(['merchant_new_order' => false, 'customer_payment_receipt' => false]);
    $second = placedOrder($this->a['tenant'], key: 'ord-key-0000002');
    $secondReference = initPayment($this->a['tenant'], $second, key: 'pay-key-0000002')->json('data.payment.reference');
    payOrder($second, $secondReference);

    expect(emailsSent('new_order'))->toHaveCount(1)->and(emailsSent('payment_receipt'))->toHaveCount(1);
});

it('falls back to the store contact email for merchant alerts', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['contact' => ['email' => 'hello@icecream.example']])->assertOk();
    app('auth')->forgetGuards();

    [$order, $reference] = orderAwaitingPayment();
    payOrder($order, $reference);

    expect(emailsSent('new_order')[0][1])->toBe('hello@icecream.example');
});

it('alerts the merchant when a payment fails', function () {
    [$order, $reference] = orderAwaitingPayment();
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($reference, $order->total, 'failed')]);

    paystackWebhook($this->a['tenant'], $reference)->assertOk();

    $alerts = emailsSent('payment_failed');
    expect($alerts)->toHaveCount(1)->and($alerts[0][1])->toBe($this->a['owner']->email);
    expect(emailsSent('payment_receipt'))->toBeEmpty();
});

it('alerts the merchant when the provider amount does not match', function () {
    [$order, $reference] = orderAwaitingPayment();
    refakeHttp(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($reference, 100)]);

    paystackWebhook($this->a['tenant'], $reference)->assertOk();

    expect(emailsSent('payment_failed'))->toHaveCount(1)->and(emailsSent('payment_receipt'))->toBeEmpty();
});

it('emails the customer when they are refunded, unless refund emails are off', function () {
    $order = placedOrder($this->a['tenant'], qty: 2);
    Order::withoutTenantScope()->whereKey($order->id)->update(['payment_status' => 'PAID', 'status' => 'PROCESSING']);
    Payment::factory()->forOrder($order)->paid()->create(['reference' => 'pay_refundmail']);
    refakeHttp(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 55]])]);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->postJson("/api/v1/orders/{$order->id}/refund", ['amount' => 100000, 'reason' => 'Damaged'], ['Idempotency-Key' => 'refund-key-0001'])->assertCreated();

    $refunds = emailsSent('refund');
    expect($refunds)->toHaveCount(1)->and($refunds[0][1])->toBe('buyer@example.com');
    expect((string) $refunds[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('1,000.00 NGN')->toContain('Damaged');

    setNotifications(['customer_refund' => false]);
    $this->postJson("/api/v1/orders/{$order->id}/refund", ['amount' => 50000], ['Idempotency-Key' => 'refund-key-0002'])->assertCreated();

    expect(emailsSent('refund'))->toHaveCount(1);
});

it('sends one low-stock alert when a sale takes stock to the threshold, not on every later sale', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 7, 'track_inventory' => true, 'name' => 'Vanilla Tub']);

    placedOrder($this->a['tenant'], $product, qty: 1, key: 'ord-key-0000001');
    expect(emailsSent('low_stock'))->toBeEmpty(); // 7 -> 6, still above 5

    placedOrder($this->a['tenant'], $product, qty: 1, key: 'ord-key-0000002');
    $alerts = emailsSent('low_stock');
    expect($alerts)->toHaveCount(1)->and($alerts[0][1])->toBe($this->a['owner']->email);
    expect((string) $alerts[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('Vanilla Tub')->toContain('running low');

    placedOrder($this->a['tenant'], $product, qty: 1, key: 'ord-key-0000003');
    expect(emailsSent('low_stock'))->toHaveCount(1); // already below the threshold
});

it('sends no low-stock alert when the threshold is 0 or the switch is off', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 6, 'track_inventory' => true]);

    setNotifications(['low_stock_threshold' => 0]);
    placedOrder($this->a['tenant'], $product, qty: 5, key: 'ord-key-0000001');
    expect(emailsSent('low_stock'))->toBeEmpty();

    $other = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 6, 'track_inventory' => true]);
    setNotifications(['low_stock_threshold' => 5, 'merchant_low_stock' => false]);
    placedOrder($this->a['tenant'], $other, qty: 3, key: 'ord-key-0000002');
    expect(emailsSent('low_stock'))->toBeEmpty();
});

it('never sends a store email to another store\'s settings', function () {
    $this->actingAs($this->b['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['customer_order_confirmation' => false, 'merchant_new_order' => false]])->assertOk();
    app('auth')->forgetGuards();

    placedOrder($this->a['tenant']); // store A still has its defaults

    expect(emailsSent('order'))->toHaveCount(1);
});

it('saves notification preferences and rejects invalid values', function () {
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->putJson('/api/v1/store/settings', ['notifications' => ['merchant_email' => 'owner@icecream.example', 'low_stock_threshold' => 3, 'customer_refund' => false]])
        ->assertOk()->assertJsonPath('data.notifications.merchant_email', 'owner@icecream.example')->assertJsonPath('data.notifications.customer_refund', false)
        ->assertJsonPath('data.notifications.merchant_low_stock', true);

    foreach ([['merchant_email' => 'not-an-email'], ['low_stock_threshold' => -1], ['low_stock_threshold' => 'lots'], ['customer_refund' => 'maybe'], ['unknown_switch' => true]] as $bad) {
        $this->putJson('/api/v1/store/settings', ['notifications' => $bad])->assertUnprocessable();
    }
});

it('returns notification defaults for a store whose saved settings predate them', function () {
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => $this->a['store']->settings()->create(['settings' => ['contact' => ['email' => 'hi@icecream.example']]]));

    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/store/settings')->assertOk()
        ->assertJsonPath('data.contact.email', 'hi@icecream.example')
        ->assertJsonPath('data.notifications.customer_order_confirmation', true)
        ->assertJsonPath('data.notifications.low_stock_threshold', 5);
});
