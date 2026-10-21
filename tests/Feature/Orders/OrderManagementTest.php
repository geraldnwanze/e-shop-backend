<?php

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 100000, 'stock' => 10]);
});

function placeOrder(Model $tenant, Product $product, int $qty = 2, string $key = 'order-key-00001'): string
{
    return checkout($tenant, makeCart($tenant, [[$product, $qty]]), key: $key)->assertCreated()->json('data.id');
}

it('lists and filters orders for the merchant', function () {
    $this->actingAs($this->a['owner'], 'sanctum');
    $one = placeOrder($this->a['tenant'], $this->product, key: 'order-key-00001');
    placeOrder($this->a['tenant'], $this->product, key: 'order-key-00002');
    Order::withoutTenantScope()->where('id', $one)->update(['status' => OrderStatus::Shipped]);

    $this->actingAs($this->a['owner'], 'sanctum');
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/orders?status=SHIPPED')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/orders?payment_status=PAID')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/orders?search=buyer@example')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/orders?search=ORD-')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/orders?status=BOGUS')->assertUnprocessable();
});

it('shows an order with items and customer', function () {
    $id = placeOrder($this->a['tenant'], $this->product);

    $this->actingAs($this->a['owner'], 'sanctum')->getJson("/api/v1/orders/{$id}")
        ->assertOk()->assertJsonPath('data.items.0.quantity', 2)->assertJsonStructure(['data' => ['customer' => ['id', 'email'], 'shippingAddress']]);
});

it('walks an order through the fulfilment lifecycle and notifies the customer', function () {
    $id = placeOrder($this->a['tenant'], $this->product);
    $this->actingAs($this->a['owner'], 'sanctum');

    foreach (['PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED'] as $status) {
        $this->patchJson("/api/v1/orders/{$id}/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
    }

    Notification::assertSentOnDemandTimes(OrderNotification::class, 5); // confirmation + 4 updates
});

it('rejects invalid transitions', function (string $from, string $to) {
    $id = placeOrder($this->a['tenant'], $this->product);
    Order::withoutTenantScope()->where('id', $id)->update(['status' => $from]);

    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/orders/{$id}/status", ['status' => $to])
        ->assertStatus(409)->assertJsonPath('meta.errorCode', 'INVALID_STATE_TRANSITION');
})->with([
    ['PENDING', 'SHIPPED'],
    ['PENDING', 'COMPLETED'],
    ['PROCESSING', 'DELIVERED'],
    ['SHIPPED', 'CANCELLED'],
    ['CANCELLED', 'PROCESSING'],
    ['COMPLETED', 'PENDING'],
]);

it('restores stock exactly once when an order is cancelled', function () {
    $id = placeOrder($this->a['tenant'], $this->product, 3);
    expect($this->product->fresh()->stock)->toBe(7);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'CANCELLED'])->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancelledAt', fn ($v) => $v !== null);
    $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'CANCELLED'])->assertStatus(409);

    expect($this->product->fresh()->stock)->toBe(10)
        ->and(InventoryMovement::withoutTenantScope()->where('reason', 'order_cancelled')->count())->toBe(1);
});

it('restores variant stock on cancellation', function () {
    $shirt = makeProduct($this->a['tenant'], [], [['stock' => 5]]);
    $id = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$shirt, 2, $shirt->variants[0]]]), key: 'order-key-00009')->json('data.id');
    expect($shirt->variants[0]->fresh()->stock)->toBe(3);

    $this->actingAs($this->a['owner'], 'sanctum')->patchJson("/api/v1/orders/{$id}/status", ['status' => 'CANCELLED'])->assertOk();

    expect($shirt->variants[0]->fresh()->stock)->toBe(5);
});

it('does not let merchants set payment status or any other field', function () {
    $id = placeOrder($this->a['tenant'], $this->product);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'PROCESSING', 'payment_status' => 'PAID', 'total' => 1])->assertOk()->assertJsonPath('data.paymentStatus', 'PENDING')->assertJsonPath('data.total', 200000);
    $this->patchJson("/api/v1/orders/{$id}/status", ['payment_status' => 'PAID'])->assertUnprocessable();
    $this->putJson("/api/v1/orders/{$id}", ['payment_status' => 'PAID'])->assertStatus(405);
});

it('lets staff manage orders but not customers or other roles', function () {
    $id = placeOrder($this->a['tenant'], $this->product);
    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

    $this->actingAs($staff, 'sanctum')->patchJson("/api/v1/orders/{$id}/status", ['status' => 'PROCESSING'])->assertOk();
    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/customers')->assertForbidden();

    $shopper = User::factory()->forTenant($this->a['tenant'], UserRole::Customer)->create();
    $this->actingAs($shopper, 'sanctum')->getJson('/api/v1/orders')->assertForbidden();
});

it('lists customers with order counts and search', function () {
    placeOrder($this->a['tenant'], $this->product);
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.ordersCount', 1)->assertJsonPath('data.0.hasAccount', false);
    $this->getJson('/api/v1/customers?search=buyer')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/customers?search=nobody')->assertJsonPath('meta.total', 0);
});
