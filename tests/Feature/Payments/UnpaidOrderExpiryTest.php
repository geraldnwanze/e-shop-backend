<?php

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

it('cancels stale unpaid orders across tenants and restores their stock', function () {
    $pa = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);
    $pb = Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 10]);
    $stale = placedOrder($this->a['tenant'], $pa, 3, 'ord-key-stale001');
    $staleB = placedOrder($this->b['tenant'], $pb, 2, 'ord-key-stale002');
    $fresh = placedOrder($this->a['tenant'], $pa, 1, 'ord-key-fresh001');
    $paid = placedOrder($this->a['tenant'], $pa, 1, 'ord-key-paid0001');
    Order::withoutTenantScope()->whereKey($paid->id)->update(['payment_status' => 'PAID', 'placed_at' => now()->subDays(3)]);
    Order::withoutTenantScope()->whereIn('id', [$stale->id, $staleB->id])->update(['placed_at' => now()->subHours(25)]);

    $this->artisan('orders:expire-unpaid')->expectsOutputToContain('Cancelled 2')->assertSuccessful();

    $status = fn (Order $o) => Order::withoutTenantScope()->find($o->id)->status->value;
    expect($status($stale))->toBe('CANCELLED')->and($status($staleB))->toBe('CANCELLED')->and($status($fresh))->toBe('PENDING')->and($status($paid))->toBe('PENDING')
        ->and($pa->fresh()->stock)->toBe(10 - 1 - 1)   // only the stale 3 returned
        ->and($pb->fresh()->stock)->toBe(10)
        ->and(InventoryMovement::withoutTenantScope()->where('reason', 'order_cancelled')->count())->toBe(2);
});

it('is a no-op when expiry is disabled', function () {
    $order = placedOrder($this->a['tenant']);
    Order::withoutTenantScope()->whereKey($order->id)->update(['placed_at' => now()->subDays(9)]);
    config(['payments.unpaid_order_expiry_hours' => 0]);

    $this->artisan('orders:expire-unpaid')->assertSuccessful();

    expect(Order::withoutTenantScope()->find($order->id)->status->value)->toBe('PENDING');
});

it('schedules both maintenance commands', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('orders:expire-unpaid')->assertSuccessful();
});
