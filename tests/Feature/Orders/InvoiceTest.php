<?php

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 100000, 'stock' => 10]);
});

function paidOrderId(PaymentStatus $status = PaymentStatus::Paid, ?Customer $customer = null, string $key = 'invoice-key-0001'): string
{
    $token = $customer?->createToken('t')->plainTextToken;
    $id = checkout(test()->a['tenant'], makeCart(test()->a['tenant'], [[test()->product, 2]], $customer), token: $token, key: $key)->assertCreated()->json('data.id');
    Order::withoutTenantScope()->whereKey($id)->update(['payment_status' => $status]);

    return $id;
}

it('downloads a PDF invoice for a paid order', function () {
    $id = paidOrderId();

    $response = $this->actingAs($this->a['owner'], 'sanctum')->get("/api/v1/orders/{$id}/invoice");

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename="Invoice-ORD-');
});

it('still issues an invoice after a refund', function () {
    $id = paidOrderId(PaymentStatus::Refunded);

    $this->actingAs($this->a['owner'], 'sanctum')->get("/api/v1/orders/{$id}/invoice")->assertOk();
});

it('refuses an invoice until the order is paid', function (PaymentStatus $status) {
    $id = paidOrderId($status);

    $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/orders/{$id}/invoice")->assertStatus(409);
})->with([PaymentStatus::Pending, PaymentStatus::Failed]);

it('keeps invoices inside their own store', function () {
    $id = paidOrderId();

    $this->actingAs($this->b['owner'], 'sanctum')->getJson("/api/v1/orders/{$id}/invoice")->assertNotFound();
});

it('requires a signed-in merchant to download an invoice', function () {
    $id = paidOrderId();

    http()->getJson("/api/v1/orders/{$id}/invoice")->assertUnauthorized();
});

it('lets a signed-in customer download only their own invoice', function () {
    $me = Customer::factory()->forTenant($this->a['tenant'])->create();
    $other = Customer::factory()->forTenant($this->a['tenant'])->create();
    $mine = paidOrderId(customer: $me, key: 'invoice-key-0002');
    $theirs = paidOrderId(customer: $other, key: 'invoice-key-0003');
    $headers = storefrontHeaders($this->a['tenant'], $me->createToken('t')->plainTextToken);

    $response = http()->get("/api/v1/storefront/account/orders/{$mine}/invoice", $headers);
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');

    http()->getJson("/api/v1/storefront/account/orders/{$theirs}/invoice", $headers)->assertNotFound();
});

it('escapes customer-supplied text on the invoice', function () {
    $order = Order::factory()->forTenant($this->a['tenant'])->make(['customer_name' => '<script>alert(1)</script>', 'order_number' => 'ORD-2026-000001']);
    $order->setRelation('items', collect([new OrderItem(['name' => '<b>Cone</b>', 'quantity' => 1, 'unit_price' => 100, 'total' => 100])]));

    $html = view('pdf.invoice', [
        'order' => $order, 'storeName' => 'Scoops', 'contact' => [], 'issuedAt' => now(), 'paidWith' => 'Paystack', 'reference' => 'ref',
        'refunded' => 0, 'money' => fn (int $m) => (string) $m,
    ])->render();

    expect($html)->not->toContain('<script>')->not->toContain('<b>Cone</b>')->toContain('&lt;script&gt;');
});
