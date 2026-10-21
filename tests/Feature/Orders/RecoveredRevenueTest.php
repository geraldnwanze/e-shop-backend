<?php

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 100000, 'stock' => 50]);
});

/** An order placed from a cart that was reminded $remindedHoursAgo hours before the order. */
function recoveredOrder(array $order = [], int $hoursAfterReminder = 3, string $stage = 'first', ?object $tenant = null): Order
{
    $tenant ??= test()->a['tenant'];
    $cart = makeCart($tenant, [[test()->product, 1]]);
    $placedAt = $order['placed_at'] ?? now()->subHour();
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['last_reminder_at' => $placedAt->copy()->subHours($hoursAfterReminder), 'last_reminder_stage' => $stage]);

    return Order::factory()->forTenant($tenant)->create($order + ['cart_id' => $cart->getKey(), 'total' => 100000, 'subtotal' => 100000, 'status' => 'PROCESSING', 'payment_status' => 'PAID', 'placed_at' => $placedAt]);
}

function recovery(int $days = 30): array
{
    return test()->actingAs(test()->a['owner'], 'sanctum')->getJson("/api/v1/dashboard/stats?days={$days}")->assertOk()->json('data.recovery');
}

it('counts paid orders placed after a reminder as recovered revenue', function () {
    recoveredOrder(['total' => 100000]);
    recoveredOrder(['total' => 250000]);

    expect(recovery())->toMatchArray(['recoveredOrders' => 2, 'recoveredRevenue' => 350000, 'cartsReminded' => 2]);
});

it('counts an unpaid recovered order as an order but not as revenue yet', function () {
    recoveredOrder(['payment_status' => 'PENDING', 'status' => 'PENDING']);

    expect(recovery())->toMatchArray(['recoveredOrders' => 1, 'recoveredRevenue' => 0]);
});

it('splits recovery between the first reminder and the follow-up', function () {
    recoveredOrder(['total' => 100000], stage: 'first');
    recoveredOrder(['total' => 300000], stage: 'second');

    expect(recovery()['byStage'])->toBe(['first' => ['orders' => 1, 'revenue' => 100000], 'second' => ['orders' => 1, 'revenue' => 300000]]);
});

it('does not credit orders that were not driven by a reminder', function () {
    $plain = makeCart($this->a['tenant'], [[$this->product, 1]]);
    Order::factory()->forTenant($this->a['tenant'])->create(['cart_id' => $plain->getKey(), 'payment_status' => 'PAID', 'placed_at' => now()]);
    Order::factory()->forTenant($this->a['tenant'])->create(['payment_status' => 'PAID', 'placed_at' => now()]);

    // reminded cart, but the order was placed before the reminder went out
    $late = makeCart($this->a['tenant'], [[$this->product, 1]]);
    Cart::withoutTenantScope()->whereKey($late->getKey())->update(['last_reminder_at' => now()]);
    Order::factory()->forTenant($this->a['tenant'])->create(['cart_id' => $late->getKey(), 'payment_status' => 'PAID', 'placed_at' => now()->subHours(5)]);

    expect(recovery())->toMatchArray(['recoveredOrders' => 0, 'recoveredRevenue' => 0, 'cartsReminded' => 1]);
});

it('only credits orders placed within a week of the reminder, and never cancelled ones', function () {
    recoveredOrder(hoursAfterReminder: 24 * 8);
    recoveredOrder(['status' => 'CANCELLED']);
    recoveredOrder(hoursAfterReminder: 24 * 6 + 23);

    expect(recovery())->toMatchArray(['recoveredOrders' => 1, 'recoveredRevenue' => 100000]);
});

it('only looks at the chosen period', function () {
    recoveredOrder(['placed_at' => now()->subDays(20)]);
    recoveredOrder(['placed_at' => now()->subHour()]);

    expect(recovery(7))->toMatchArray(['recoveredOrders' => 1])
        ->and(recovery(30))->toMatchArray(['recoveredOrders' => 2]);
});

it('never mixes in another store\'s recoveries', function () {
    recoveredOrder(tenant: $this->b['tenant']);

    expect(recovery())->toMatchArray(['recoveredOrders' => 0, 'recoveredRevenue' => 0, 'cartsReminded' => 0]);
});

it('remembers which cart an order came from at checkout', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);

    $id = checkout($this->a['tenant'], $cart, key: 'recover-key-0001')->assertCreated()->json('data.id');

    expect(Order::withoutTenantScope()->find($id)->cart_id)->toBe($cart->getKey());
});

it('keeps crediting a reminder even if the shopper later gives a new email', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);
    Cart::withoutTenantScope()->whereKey($cart->getKey())->update(['email' => 'old@mail.test', 'reminder_sent_at' => now(), 'last_reminder_at' => now(), 'last_reminder_stage' => 'first']);

    http()->putJson("/api/v1/storefront/cart/{$cart->getKey()}/contact", ['email' => 'new@mail.test'], storefrontHeaders($this->a['tenant']))->assertOk();

    $fresh = Cart::withoutTenantScope()->find($cart->getKey());
    expect($fresh->reminder_sent_at)->toBeNull()->and($fresh->last_reminder_at)->not->toBeNull();
});
