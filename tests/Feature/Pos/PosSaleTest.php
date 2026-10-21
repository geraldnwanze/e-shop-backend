<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 10, 'name' => 'Pistachio']);
});

function sale(array $overrides = [], string $key = 'pos-key-000001')
{
    return test()->actingAs(test()->a['owner'], 'sanctum')->postJson('/api/v1/pos/sales', array_replace_recursive([
        'items' => [['product_id' => test()->product->id, 'quantity' => 2]],
        'payment_method' => 'transfer',
    ], $overrides), ['Idempotency-Key' => $key]);
}

it('records a paid and completed in-store sale and takes stock', function () {
    sale()->assertCreated()
        ->assertJsonPath('data.channel', 'pos')->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.paymentStatus', 'PAID')
        ->assertJsonPath('data.total', 300000)->assertJsonPath('data.paymentMethod', 'transfer')
        ->assertJsonPath('data.customer.name', 'Walk-in customer')->assertJsonPath('data.items.0.quantity', 2);

    expect($this->product->fresh()->stock)->toBe(8)
        ->and(InventoryMovement::withoutTenantScope()->where('reason', 'order_placed')->count())->toBe(1)
        ->and(Order::withoutTenantScope()->first()->cashier_id)->toBe($this->a['owner']->id);
});

it('prices from the database and ignores client prices', function () {
    sale(['items' => [['price' => 1, 'unit_price' => 1]]])->assertCreated()->assertJsonPath('data.items.0.unitPrice', 150000);
});

it('uses variant prices and insists on a variant for products that have them', function () {
    $shirt = makeProduct($this->a['tenant'], ['price' => 1000], [['price' => 1500, 'stock' => 3], ['price' => null, 'stock' => 3]]);

    sale(['items' => [['product_id' => $shirt->id, 'variant_id' => null, 'quantity' => 1]]], 'pos-key-000002')
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['items.0.variant_id']]]);

    sale(['items' => [['product_id' => $shirt->id, 'variant_id' => $shirt->variants[0]->id, 'quantity' => 2]]], 'pos-key-000003')
        ->assertCreated()->assertJsonPath('data.total', 3000)->assertJsonPath('data.items.0.variantName', $shirt->variants[0]->name);
});

it('works out change for cash and rejects too little cash', function () {
    sale(['payment_method' => 'cash', 'amount_tendered' => 350000], 'pos-key-000004')
        ->assertCreated()->assertJsonPath('data.amountTendered', 350000)->assertJsonPath('data.change', 50000);

    sale(['payment_method' => 'cash', 'amount_tendered' => 100000], 'pos-key-000005')
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['amount_tendered']]]);

    expect($this->product->fresh()->stock)->toBe(8);
});

it('applies a sale discount but never more than the sale', function () {
    sale(['discount_amount' => 50000], 'pos-key-000006')->assertCreated()->assertJsonPath('data.discountTotal', 50000)->assertJsonPath('data.total', 250000);
    sale(['discount_amount' => 300001], 'pos-key-000007')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['discount_amount']]]);
});

it('refuses to sell more than is in stock or an unavailable product', function () {
    sale(['items' => [['quantity' => 11]]], 'pos-key-000008')->assertStatus(409);
    expect($this->product->fresh()->stock)->toBe(10);

    $this->product->update(['status' => 'draft']);
    sale([], 'pos-key-000009')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['items.0.product_id']]]);
});

it('does not charge twice when the same sale is sent again', function () {
    sale()->assertCreated();
    sale()->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Order::withoutTenantScope()->count())->toBe(1)->and($this->product->fresh()->stock)->toBe(8);
});

it('keeps another store\'s products out of a sale', function () {
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5]);

    sale(['items' => [['product_id' => $theirs->id]]], 'pos-key-000010')->assertUnprocessable();
    expect($theirs->fresh()->stock)->toBe(5);
});

it('requires a signed-in merchant', function () {
    http()->postJson('/api/v1/pos/sales', ['items' => [], 'payment_method' => 'cash'], ['Idempotency-Key' => 'pos-key-000011'])->assertUnauthorized();
});

it('voids a sale: stock returns and it stops counting as revenue', function () {
    $id = sale()->json('data.id');

    $this->actingAs($this->a['owner'], 'sanctum')->postJson("/api/v1/orders/{$id}/void")
        ->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.paymentStatus', 'REFUNDED');

    expect($this->product->fresh()->stock)->toBe(10)
        ->and(Order::withoutTenantScope()->find($id)->status)->toBe(OrderStatus::Cancelled);

    $this->postJson("/api/v1/orders/{$id}/void")->assertStatus(409);
});

it('only voids in-store sales and only inside the store', function () {
    $online = Order::factory()->forTenant($this->a['tenant'])->create(['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
    $this->actingAs($this->a['owner'], 'sanctum')->postJson("/api/v1/orders/{$online->id}/void")->assertStatus(409);

    $id = sale([], 'pos-key-000012')->json('data.id');
    $this->actingAs($this->b['owner'], 'sanctum')->postJson("/api/v1/orders/{$id}/void")->assertNotFound();
});

it('filters orders by channel and issues an in-store invoice', function () {
    $id = sale()->json('data.id');
    Order::factory()->forTenant($this->a['tenant'])->create();

    $this->getJson('/api/v1/orders?channel=pos')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson('/api/v1/orders?channel=online')->assertJsonPath('meta.total', 1);

    $pdf = $this->get("/api/v1/orders/{$id}/invoice");
    $pdf->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF');
});

it('finds products by variant SKU for the register', function () {
    $shirt = makeProduct($this->a['tenant'], ['name' => 'Shirt'], [['sku' => 'SHIRT-RED-L', 'stock' => 2]]);

    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/products?search=SHIRT-RED')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shirt->id);
});
