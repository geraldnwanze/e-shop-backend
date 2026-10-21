<?php

use App\Events\OrderPlaced;
use App\Listeners\SendOrderNotification;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/*
| Assume a malicious merchant: every request below is made as tenant B's owner
| using tenant A's identifiers. None of them may reveal or alter A's data.
*/

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');

    $this->productA = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'A Secret', 'stock' => 10]);
    $this->categoryA = Category::factory()->forTenant($this->a['tenant'])->create();
    $this->discountA = Discount::factory()->forTenant($this->a['tenant'])->create();
    $this->orderUuid = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->productA, 1]]))->assertCreated()->json('data.id');
    $this->customerA = Customer::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->firstOrFail();
    $this->productB = Product::factory()->forTenant($this->b['tenant'])->create(['name' => 'B Item']);
});

it('prevents Tenant B from reading Tenant A products', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    $this->getJson("/api/v1/products/{$this->productA->id}")->assertNotFound();
    $this->putJson("/api/v1/products/{$this->productA->id}", ['name' => 'pwned'])->assertNotFound();
    $this->deleteJson("/api/v1/products/{$this->productA->id}")->assertNotFound();
    $this->postJson("/api/v1/products/{$this->productA->id}/inventory", ['delta' => 99, 'reason' => 'restock'])->assertNotFound();
    $this->getJson("/api/v1/products/{$this->productA->id}/inventory")->assertNotFound();

    $list = $this->getJson('/api/v1/products')->assertOk();
    expect(collect($list->json('data'))->pluck('name')->all())->toBe(['B Item']);
    expect($this->productA->fresh()->name)->toBe('A Secret')->and($this->productA->fresh()->stock)->toBe(9);
});

it('prevents Tenant B from reading or changing Tenant A orders', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    $this->getJson("/api/v1/orders/{$this->orderUuid}")->assertNotFound();
    $this->patchJson("/api/v1/orders/{$this->orderUuid}/status", ['status' => 'CANCELLED'])->assertNotFound();
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/orders?search=buyer@example.com')->assertJsonPath('meta.total', 0);

    expect(Order::withoutTenantScope()->where('id', $this->orderUuid)->value('status')->value)->toBe('PENDING');
});

it('prevents Tenant B from reading Tenant A customers', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    $this->getJson("/api/v1/customers/{$this->customerA->id}")->assertNotFound();
    $this->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/customers?search=buyer')->assertJsonPath('meta.total', 0);
});

it('prevents Tenant B from touching Tenant A categories and discounts', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    $this->getJson("/api/v1/categories/{$this->categoryA->id}")->assertNotFound();
    $this->putJson("/api/v1/categories/{$this->categoryA->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/categories/{$this->categoryA->id}")->assertNotFound();
    $this->getJson("/api/v1/discounts/{$this->discountA->id}")->assertNotFound();
    $this->getJson('/api/v1/categories')->assertJsonCount(0, 'data');
});

it('prevents attaching Tenant A resources to Tenant B products via ids in the payload', function () {
    $this->actingAs($this->b['owner'], 'sanctum');

    $this->putJson("/api/v1/products/{$this->productB->id}", ['category_id' => $this->categoryA->id])->assertUnprocessable();
    $this->postJson('/api/v1/products', ['name' => 'x', 'price' => 1, 'category_id' => $this->categoryA->id])->assertUnprocessable();
});

it('serves each storefront only its own catalogue, and never its own host\'s data on the other\'s', function () {
    $a = http()->getJson('/api/v1/storefront/products', storefrontHeaders($this->a['tenant']))->json('data');
    $b = http()->getJson('/api/v1/storefront/products', storefrontHeaders($this->b['tenant']))->json('data');

    expect(collect($a)->pluck('name')->all())->toBe(['A Secret'])->and(collect($b)->pluck('name')->all())->toBe(['B Item']);
    http()->getJson("/api/v1/storefront/products/{$this->productA->slug}", storefrontHeaders($this->b['tenant']))->assertNotFound();
});

it('cannot be tricked into another tenant via a forged acting-tenant or storefront header on merchant routes', function () {
    $this->actingAs($this->b['owner'], 'sanctum');
    $headers = ['X-Acting-Tenant' => $this->a['tenant']->id, 'X-Storefront-Host' => 'icecream.mydomain.test'];

    $list = $this->getJson('/api/v1/products?tenant_id='.$this->a['tenant']->id, $headers)->assertOk();
    expect(collect($list->json('data'))->pluck('name')->all())->toBe(['B Item']);
    $this->getJson("/api/v1/orders/{$this->orderUuid}", $headers)->assertNotFound();
});

it('keeps queued work tenant-aware: the notification listener re-enters the tenant by id', function () {
    $order = Order::withoutTenantScope()->where('id', $this->orderUuid)->firstOrFail();
    app(TenantContext::class)->clear();

    // No ambient tenant (as in a queue worker): the listener must still find the order.
    (new SendOrderNotification(app(TenantContext::class)))->handle(new OrderPlaced($order->tenant_id, $order->id));

    Notification::assertSentOnDemandTimes(OrderNotification::class, 2);
    expect(app(TenantContext::class)->hasTenant())->toBeFalse();
});

it('does not apply a queued listener to an order id that belongs to another tenant', function () {
    $order = Order::withoutTenantScope()->where('id', $this->orderUuid)->firstOrFail();

    (new SendOrderNotification(app(TenantContext::class)))->handle(new OrderPlaced($this->b['tenant']->id, $order->id));

    Notification::assertSentOnDemandTimes(OrderNotification::class, 1); // only the original checkout email
});
