<?php

use App\Models\Customer;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\ReviewRequestMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->p1 = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Pistachio', 'slug' => 'pistachio']);
    $this->p2 = Product::factory()->forTenant($this->a['tenant'])->create(['name' => 'Vanilla', 'slug' => 'vanilla']);
});

function requestsOn(array $extra = []): void
{
    test()->actingAs(test()->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => $extra + ['review_request' => true]])->assertOk();
    app('auth')->forgetGuards();
}

/**
 * A delivered order for $email, delivered $daysAgo days ago, containing the given products.
 *
 * @param  list<Product>  $products
 */
function deliveredOrder(int $daysAgo = 5, string $email = 'ada@mail.test', ?array $products = null, array $attributes = []): Order
{
    $tenant = test()->a['tenant'];
    $customer = Customer::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('email', $email)->first()
        ?? Customer::factory()->forTenant($tenant)->create(['email' => $email, 'first_name' => 'Ada']);

    return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $customer, $email, $daysAgo, $products, $attributes) {
        $order = Order::factory()->forTenant($tenant)->create($attributes + [
            'customer_id' => $customer->id, 'customer_email' => $email, 'customer_name' => 'Ada Obi', 'status' => 'DELIVERED', 'payment_status' => 'PAID',
            'delivered_at' => now()->subDays($daysAgo), 'review_requested_at' => null,
        ]);

        foreach ($products ?? [test()->p1, test()->p2] as $p) {
            $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'unit_price' => 1000, 'quantity' => 1, 'total' => 1000]);
        }

        return $order;
    });
}

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function reviewRequests(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'review_request') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function runRequests(): void
{
    test()->artisan('reviews:send-requests')->assertSuccessful();
}

it('does nothing until the store switches it on', function () {
    deliveredOrder();

    runRequests();

    expect(reviewRequests())->toBeEmpty();
});

it('asks for a review after the delay, with a link per product and an unsubscribe link', function () {
    requestsOn();
    $order = deliveredOrder();

    runRequests();

    [[$notification, $to]] = reviewRequests();
    expect($to)->toBe('ada@mail.test')
        ->and($notification->subject)->toBe('How was your order?')
        ->and($notification->message['intro'])->toContain('Hi Ada')->toContain($order->order_number)
        ->and(collect($notification->message['links'])->pluck(0)->all())->toBe(['Review Pistachio', 'Review Vanilla'])
        ->and($notification->message['links'][0][1])->toEndWith('/products/pistachio#reviews')
        ->and($notification->message['unsubscribe_url'])->toContain("/api/v1/unsubscribe/{$this->a['tenant']->id}")
        ->and($order->fresh()->review_requested_at)->not->toBeNull();
});

it('asks only once per order', function () {
    requestsOn();
    deliveredOrder();

    runRequests();
    runRequests();

    expect(reviewRequests())->toHaveCount(1);
});

it('waits for the store\'s chosen number of days after delivery', function () {
    requestsOn(['review_request_delay_days' => 7]);
    deliveredOrder(5);

    runRequests();
    expect(reviewRequests())->toBeEmpty();

    Order::withoutTenantScope()->update(['delivered_at' => now()->subDays(8)]);
    runRequests();
    expect(reviewRequests())->toHaveCount(1);
});

it('leaves out products the customer already reviewed, and sends nothing if they reviewed everything', function () {
    requestsOn();
    $order = deliveredOrder();
    ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->p1->id, 'customer_id' => $order->customer_id]);

    runRequests();
    expect(collect(reviewRequests()[0][0]->message['links'])->pluck(0)->all())->toBe(['Review Vanilla']);

    $second = deliveredOrder(5, 'bola@mail.test');
    ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->p1->id, 'customer_id' => $second->customer_id]);
    ProductReview::factory()->forTenant($this->a['tenant'])->create(['product_id' => $this->p2->id, 'customer_id' => $second->customer_id]);

    runRequests();
    expect(reviewRequests())->toHaveCount(1)->and($second->fresh()->review_requested_at)->not->toBeNull();
});

it('skips products that are no longer on sale', function () {
    requestsOn();
    deliveredOrder();
    $this->p2->update(['status' => 'draft']);

    runRequests();

    expect(collect(reviewRequests()[0][0]->message['links'])->pluck(0)->all())->toBe(['Review Pistachio']);
});

it('never asks about in-store, cancelled, unpaid or very old orders', function () {
    requestsOn();
    deliveredOrder(5, 'pos@mail.test', attributes: ['channel' => 'pos']);
    deliveredOrder(5, 'cancelled@mail.test', attributes: ['status' => 'CANCELLED']);
    deliveredOrder(5, 'unpaid@mail.test', attributes: ['payment_status' => 'PENDING']);
    deliveredOrder(5, 'refunded@mail.test', attributes: ['payment_status' => 'REFUNDED']);
    deliveredOrder(90, 'old@mail.test');

    runRequests();

    expect(reviewRequests())->toBeEmpty();
});

it('respects people who unsubscribed', function () {
    requestsOn();
    $order = deliveredOrder();
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'ada@mail.test']);

    runRequests();

    expect(reviewRequests())->toBeEmpty()->and($order->fresh()->review_requested_at)->not->toBeNull();
});

it('sends one request per customer a week even with several delivered orders', function () {
    requestsOn();
    $first = deliveredOrder(6);
    $second = deliveredOrder(5);

    runRequests();
    expect(reviewRequests())->toHaveCount(1)->and($second->fresh()->review_requested_at)->toBeNull();

    Order::withoutTenantScope()->whereKey($first->id)->update(['review_requested_at' => now()->subDays(8)]);
    runRequests();
    expect(reviewRequests())->toHaveCount(2)->and($second->fresh()->review_requested_at)->not->toBeNull();
});

it('stays quiet when reviews are switched off or the owner has not verified their email', function () {
    requestsOn();
    deliveredOrder();
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['reviews' => ['enabled' => false]])->assertOk();

    runRequests();
    expect(reviewRequests())->toBeEmpty();

    $this->putJson('/api/v1/store/settings', ['reviews' => ['enabled' => true]])->assertOk();
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    config(['tenancy.require_verified_email_to_sell' => true]);

    runRequests();
    expect(reviewRequests())->toBeEmpty();
});

it('never mixes stores', function () {
    requestsOn();
    $theirs = Customer::factory()->forTenant($this->b['tenant'])->create(['email' => 'ada@mail.test']);
    app(TenantContext::class)->runAs($this->b['tenant'], fn () => Order::factory()->forTenant($this->b['tenant'])->create([
        'customer_id' => $theirs->id, 'customer_email' => 'ada@mail.test', 'status' => 'DELIVERED', 'payment_status' => 'PAID', 'delivered_at' => now()->subDays(5),
    ]));

    runRequests();

    expect(reviewRequests())->toBeEmpty();
});

it('records when an order is delivered, and keeps that time when it is completed', function () {
    $order = Order::factory()->forTenant($this->a['tenant'])->create(['status' => 'SHIPPED', 'payment_status' => 'PAID']);
    expect($order->delivered_at)->toBeNull();
    $this->actingAs($this->a['owner'], 'sanctum');

    $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'DELIVERED'])->assertOk();
    $deliveredAt = $order->fresh()->delivered_at;
    expect($deliveredAt)->not->toBeNull();

    $this->travel(2)->days();
    $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'COMPLETED'])->assertOk();
    expect($order->fresh()->delivered_at->equalTo($deliveredAt))->toBeTrue();
});

it('emails the merchant a test of the request, and validates the settings', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/store/settings/test-email', ['type' => 'review_request', 'email' => 'victim@mail.test'])
        ->assertOk()->assertJsonPath('data.sentTo', $this->a['owner']->email);

    $sent = collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])->flatten(2)->first();
    expect($sent['notification']->subject)->toBe('[Test] How was your order?')->and($sent['notifiable']->routes['mail'])->toBe($this->a['owner']->email)
        ->and($sent['notification']->message['unsubscribe_url'])->toBeNull();

    $this->putJson('/api/v1/store/settings', ['notifications' => ['review_request_delay_days' => 0]])->assertUnprocessable();
    $this->putJson('/api/v1/store/settings', ['notifications' => ['review_request_delay_days' => 31]])->assertUnprocessable();
});

it('escapes product names in the request email', function () {
    $content = ReviewRequestMessage::build(['store' => 'Scoops', 'accent' => '#5b3df5', 'supportEmail' => null], '<b>Ada</b>', 'ORD-1', [['<script>x</script>', 'https://s.test/products/x#reviews']], null);

    $html = view('emails.branded', ['content' => $content])->render();

    expect($html)->not->toContain('<script>x')->toContain('&lt;script&gt;')->toContain('https://s.test/products/x#reviews');
});
