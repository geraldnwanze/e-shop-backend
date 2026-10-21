<?php

use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\Product;
use App\Models\Store;
use App\Notifications\OrderNotification;
use App\Services\Themes\StoreCustomizationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->vanilla = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Vanilla', 'price' => 150000, 'stock' => 10]);
    $this->cookie = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Cookie', 'price' => 50000, 'stock' => 3]);
});

it('places an order with server-computed totals and snapshots the lines', function () {
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 2], [$this->cookie, 1]]);

    $response = checkout($this->a['tenant'], $cart)->assertCreated()
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.paymentStatus', 'PENDING')
        ->assertJsonPath('data.subtotal', 350000)
        ->assertJsonPath('data.total', 350000)
        ->assertJsonPath('data.currency', 'NGN')
        ->assertJsonPath('data.customer.email', 'buyer@example.com')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.unitPrice', 150000);

    expect($response->json('data.orderNumber'))->toMatch('/^ORD-\d{4}-000001$/');

    $order = Order::withoutTenantScope()->where('id', $response->json('data.id'))->firstOrFail();
    expect($order->tenant_id)->toBe($this->a['tenant']->id)
        ->and($order->placed_authenticated)->toBeFalse()
        ->and($this->vanilla->fresh()->stock)->toBe(8)
        ->and($this->cookie->fresh()->stock)->toBe(2)
        ->and(Cart::withoutTenantScope()->find($cart->id)->converted_at)->not->toBeNull()
        ->and(InventoryMovement::withoutTenantScope()->where('order_id', $order->id)->sum('delta'))->toBe(-3);
});

it('snapshots prices so later catalogue changes do not alter the order', function () {
    $order = checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]))->assertCreated();

    $this->vanilla->update(['price' => 999999, 'name' => 'Renamed']);

    $stored = Order::withoutTenantScope()->where('id', $order->json('data.id'))->firstOrFail();
    expect($stored->items()->withoutGlobalScopes()->first()->only(['name', 'unit_price']))->toBe(['name' => 'Vanilla', 'unit_price' => 150000]);
});

it('ignores any totals, statuses or prices supplied by the client', function () {
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 1]]);

    checkout($this->a['tenant'], $cart, [
        'total' => 1, 'subtotal' => 1, 'status' => 'COMPLETED', 'payment_status' => 'PAID', 'tenant_id' => $this->b['tenant']->id,
        'items' => [['price' => 1]], 'shipping_total' => 0,
    ])->assertCreated()->assertJsonPath('data.total', 150000)->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.paymentStatus', 'PENDING');

    $order = Order::withoutTenantScope()->latest('id')->first();
    expect($order->tenant_id)->toBe($this->a['tenant']->id)->and($order->payment_status)->toBe(PaymentStatus::Pending);
});

it('numbers orders sequentially per tenant', function () {
    foreach ([1, 2] as $i) {
        checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), key: "key-a-0000000{$i}");
    }
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create();
    $b = checkout($this->b['tenant'], makeCart($this->b['tenant'], [[$theirs, 1]]), key: 'key-b-00000001');

    $numbers = Order::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->orderBy('id')->pluck('order_number')->all();
    $year = now()->year;
    expect($numbers)->toBe(["ORD-{$year}-000001", "ORD-{$year}-000002"])
        ->and($b->json('data.orderNumber'))->toBe("ORD-{$year}-000001");
});

it('adds the store flat shipping rate', function () {
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => app(StoreCustomizationService::class)->updateSettings(Store::query()->firstOrFail(), ['checkout' => ['shipping_flat_rate' => 25000]]));

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]))
        ->assertJsonPath('data.shippingTotal', 25000)->assertJsonPath('data.total', 175000);
});

it('applies percentage and fixed discounts and counts redemptions', function () {
    Discount::factory()->forTenant($this->a['tenant'])->create(['code' => 'TEN', 'value' => 10]);
    Discount::factory()->forTenant($this->a['tenant'])->fixed(500000)->create(['code' => 'HUGE']);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 2]]), ['discount_code' => ' ten '], key: 'key-pct-0001')
        ->assertCreated()->assertJsonPath('data.discountTotal', 30000)->assertJsonPath('data.total', 270000)->assertJsonPath('data.discountCode', 'TEN');
    // fixed discount can never exceed the subtotal
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), ['discount_code' => 'HUGE'], key: 'key-fix-0001')
        ->assertJsonPath('data.discountTotal', 150000)->assertJsonPath('data.total', 0);

    expect(Discount::withoutTenantScope()->where('code', 'TEN')->value('used_count'))->toBe(1);
});

it('rejects invalid, expired, exhausted, below-minimum and foreign discount codes', function (array $state, ?string $code = null) {
    Discount::factory()->forTenant($this->a['tenant'])->create($state + ['code' => 'PROMO']);
    Discount::factory()->forTenant($this->b['tenant'])->create(['code' => 'THEIRS']);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), ['discount_code' => $code ?? 'PROMO'])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['discount_code']]]);
    expect(Order::withoutTenantScope()->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'expired' => [['ends_at' => now()->subDay()]],
    'not started' => [['starts_at' => now()->addDay()]],
    'exhausted' => [['max_uses' => 1, 'used_count' => 1]],
    'below minimum' => [['min_subtotal' => 999999999]],
    'unknown' => [[], 'NOPE'],
    'other tenant' => [[], 'THEIRS'],
]);

it('rolls everything back when stock is insufficient', function () {
    Discount::factory()->forTenant($this->a['tenant'])->create(['code' => 'TEN', 'max_uses' => 5]);
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 2], [$this->cookie, 2]]);
    $this->cookie->update(['stock' => 1]); // someone else bought it meanwhile

    checkout($this->a['tenant'], $cart, ['discount_code' => 'TEN'])
        ->assertStatus(409)->assertJsonPath('meta.errorCode', 'INSUFFICIENT_STOCK');

    expect(Order::withoutTenantScope()->count())->toBe(0)
        ->and($this->vanilla->fresh()->stock)->toBe(10)
        ->and($this->cookie->fresh()->stock)->toBe(1)
        ->and(InventoryMovement::withoutTenantScope()->count())->toBe(0)
        ->and(Discount::withoutTenantScope()->where('code', 'TEN')->value('used_count'))->toBe(0)
        ->and(OrderSequence::withoutTenantScope()->sum('last_number'))->toBe(0)
        ->and(Cart::withoutTenantScope()->find($cart->id)->converted_at)->toBeNull();
});

it('does not oversell the last unit', function () {
    $this->cookie->update(['stock' => 1]);
    $first = makeCart($this->a['tenant'], [[$this->cookie, 1]]);
    $second = makeCart($this->a['tenant'], [[$this->cookie, 1]]);

    checkout($this->a['tenant'], $first, key: 'key-race-0001')->assertCreated();
    checkout($this->a['tenant'], $second, key: 'key-race-0002')->assertStatus(409);

    expect($this->cookie->fresh()->stock)->toBe(0);
});

it('does not decrement stock for untracked products', function () {
    $service = Product::factory()->forTenant($this->a['tenant'])->create(['track_inventory' => false, 'stock' => 0]);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$service, 3]]))->assertCreated();

    expect($service->fresh()->stock)->toBe(0)->and(InventoryMovement::withoutTenantScope()->count())->toBe(0);
});

it('decrements variant stock, not product stock', function () {
    $shirt = makeProduct($this->a['tenant'], ['stock' => 10, 'price' => 1000], [['stock' => 4, 'price' => 1200]]);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$shirt, 3, $shirt->variants[0]]]))
        ->assertCreated()->assertJsonPath('data.items.0.unitPrice', 1200)->assertJsonPath('data.items.0.variantName', $shirt->variants[0]->name);

    expect($shirt->variants[0]->fresh()->stock)->toBe(1);
});

it('refuses empty carts, already-converted carts and unavailable products', function () {
    checkout($this->a['tenant'], makeCart($this->a['tenant'], []))->assertUnprocessable()->assertJsonPath('meta.errorCode', 'EMPTY_CART');

    $done = makeCart($this->a['tenant'], [[$this->vanilla, 1]]);
    checkout($this->a['tenant'], $done, key: 'key-done-0001')->assertCreated();
    checkout($this->a['tenant'], $done, key: 'key-done-0002')->assertStatus(409);

    $cart = makeCart($this->a['tenant'], [[$this->cookie, 1]]);
    $this->cookie->update(['status' => 'draft']);
    checkout($this->a['tenant'], $cart, key: 'key-draft-0001')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['cart']]]);
});

it('cannot check out another store\'s cart', function () {
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 1]]);

    checkout($this->b['tenant'], $cart)->assertNotFound();
    expect(Order::withoutTenantScope()->count())->toBe(0);
});

it('validates customer and address details', function (array $override, string $field) {
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 1]]);

    checkout($this->a['tenant'], $cart, $override)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'bad email' => [['customer' => ['email' => 'nope']], 'customer.email'],
    'missing city' => [['shipping_address' => ['city' => '']], 'shipping_address.city'],
    'bad country' => [['shipping_address' => ['country' => 'Nigeria']], 'shipping_address.country'],
]);

it('requires a phone when the store demands it', function () {
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => app(StoreCustomizationService::class)->updateSettings(Store::query()->firstOrFail(), ['checkout' => ['require_phone' => true]]));

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), ['customer' => ['phone' => null]])
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['customer.phone']]]);
});

it('blocks guest checkout when the store disables it', function () {
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => app(StoreCustomizationService::class)->updateSettings(Store::query()->firstOrFail(), ['checkout' => ['allow_guest_checkout' => false]]));

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]))->assertForbidden();

    $customer = Customer::factory()->forTenant($this->a['tenant'])->create();
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]], $customer), token: $customer->createToken('t')->plainTextToken, key: 'key-auth-0001')->assertCreated();
});

it('creates a passwordless guest customer and never overwrites an existing account', function () {
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), ['customer' => ['email' => 'new@example.com']])->assertCreated();
    $guest = Customer::withoutTenantScope()->where('email', 'new@example.com')->firstOrFail();
    expect($guest->hasAccount())->toBeFalse()->and($guest->tenant_id)->toBe($this->a['tenant']->id);

    $account = Customer::factory()->forTenant($this->a['tenant'])->create(['email' => 'member@example.com', 'first_name' => 'Real']);
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]), ['customer' => ['email' => 'member@example.com', 'first_name' => 'Attacker']], key: 'key-over-0001')->assertCreated();

    expect($account->fresh()->first_name)->toBe('Real');
    expect(Order::withoutTenantScope()->where('customer_id', $account->id)->first()->placed_authenticated)->toBeFalse();
});

it('uses the signed-in customer identity and marks the order as theirs', function () {
    $customer = Customer::factory()->forTenant($this->a['tenant'])->create(['email' => 'me@example.com', 'first_name' => 'Me', 'last_name' => 'Myself']);
    $cart = makeCart($this->a['tenant'], [[$this->vanilla, 1]], $customer);

    checkout($this->a['tenant'], $cart, ['customer' => ['email' => 'spoof@example.com']], token: $customer->createToken('t')->plainTextToken)
        ->assertCreated()->assertJsonPath('data.customer.email', 'me@example.com')->assertJsonPath('data.customer.name', 'Me Myself');

    expect(Order::withoutTenantScope()->latest('id')->first()->placed_authenticated)->toBeTrue();
});

it('queues a confirmation email built from the order', function () {
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->vanilla, 1]]))->assertCreated();

    Notification::assertSentOnDemand(OrderNotification::class, function (OrderNotification $n, array $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'buyer@example.com'
            && str_starts_with($n->summary['order_number'], 'ORD-')
            && $n->summary['total'] === '1,500.00 NGN'
            && $n->summary['store'] === $this->a['store']->name;
    });
});

it('does not send the confirmation when the order rolls back', function () {
    $this->cookie->update(['stock' => 0]);
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->cookie, 1]]))->assertStatus(409);

    Notification::assertNothingSent();
});
