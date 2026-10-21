<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

function seedOrder(Tenant $tenant, array $attrs = []): Order
{
    return Order::factory()->forTenant($tenant)->create($attrs + ['total' => 100000, 'subtotal' => 100000, 'status' => 'PROCESSING', 'payment_status' => 'PAID', 'placed_at' => now()]);
}

it('summarises revenue, orders and refunds for the current store only', function () {
    seedOrder($this->a['tenant'], ['total' => 100000]);
    seedOrder($this->a['tenant'], ['total' => 50000, 'placed_at' => now()->subDays(2)]);
    seedOrder($this->a['tenant'], ['total' => 70000, 'payment_status' => 'PENDING', 'status' => 'PENDING']);
    seedOrder($this->a['tenant'], ['total' => 90000, 'status' => 'CANCELLED', 'payment_status' => 'PENDING']);
    seedOrder($this->a['tenant'], ['total' => 10000, 'placed_at' => now()->subDays(60)]);
    seedOrder($this->b['tenant'], ['total' => 999999]);

    $order = seedOrder($this->a['tenant'], ['total' => 20000]);
    $payment = Payment::factory()->forOrder($order)->paid()->create();
    Refund::unguarded(fn () => Refund::create(['tenant_id' => $this->a['tenant']->id, 'payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => 5000, 'status' => 'succeeded']));

    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/dashboard/stats?days=30')->assertOk()
        ->assertJsonPath('data.grossRevenue', 170000)
        ->assertJsonPath('data.refunded', 5000)
        ->assertJsonPath('data.netRevenue', 165000)
        ->assertJsonPath('data.orders', 4)
        ->assertJsonPath('data.awaitingPayment', 1)
        ->assertJsonPath('data.toFulfil', 4) // all-time, not range-limited
        ->assertJsonPath('data.currency', 'NGN')
        ->assertJsonCount(30, 'data.series')
        ->assertJsonCount(5, 'data.recentOrders');
});

it('builds a zero-filled daily series ending today', function () {
    seedOrder($this->a['tenant'], ['total' => 40000]);

    $series = $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/dashboard/stats?days=7')->json('data.series');

    expect($series)->toHaveCount(7)->and(end($series))->toBe(['date' => now()->toDateString(), 'orders' => 1, 'revenue' => 40000])
        ->and($series[0]['revenue'])->toBe(0);
});

it('ranks top products and counts low stock and new customers', function () {
    $order = seedOrder($this->a['tenant']);
    app(TenantContext::class)->runAs($this->a['tenant'], function () use ($order) {
        foreach ([['Vanilla', 5], ['Chocolate', 2], ['Mint', 9]] as [$name, $qty]) {
            $order->items()->create(['name' => $name, 'unit_price' => 1000, 'quantity' => $qty, 'total' => 1000 * $qty]);
        }
    });
    Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 2]);
    Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 50]);
    Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 1]);
    Customer::factory()->forTenant($this->a['tenant'])->count(2)->create();
    Customer::factory()->forTenant($this->b['tenant'])->create();

    $data = $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/dashboard/stats')->json('data');

    expect(collect($data['topProducts'])->pluck('name')->all())->toBe(['Mint', 'Vanilla', 'Chocolate'])
        ->and($data['lowStockProducts'])->toBe(1)->and($data['newCustomers'])->toBe(2);
});

it('validates the range and enforces permissions', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/dashboard/stats?days=45')->assertUnprocessable();

    $shopper = User::factory()->forTenant($this->a['tenant'], UserRole::Customer)->create();
    $this->actingAs($shopper, 'sanctum')->getJson('/api/v1/dashboard/stats')->assertForbidden();

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/dashboard/stats')->assertOk();

    http()->getJson('/api/v1/dashboard/stats')->assertUnauthorized();
});
