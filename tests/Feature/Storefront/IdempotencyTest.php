<?php

use App\Exceptions\BaseApiException;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderNotification;
use App\Services\Idempotency\IdempotencyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 100000, 'stock' => 10]);
});

it('requires a well-formed Idempotency-Key', function (?string $key) {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);

    http()->postJson('/api/v1/storefront/checkout', checkoutPayload($cart), storefrontHeaders($this->a['tenant'], idempotencyKey: $key))
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['Idempotency-Key']]]);
    expect(Order::withoutTenantScope()->count())->toBe(0);
})->with([null, 'short', 'has spaces in it!!']);

it('replays the stored response for a retried request without creating a second order', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 2]]);

    $first = checkout($this->a['tenant'], $cart, key: 'retry-key-0001')->assertCreated();
    $second = checkout($this->a['tenant'], $cart, key: 'retry-key-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Order::withoutTenantScope()->count())->toBe(1)
        ->and($this->product->fresh()->stock)->toBe(8);
    Notification::assertSentOnDemandTimes(OrderNotification::class, 1);
});

it('rejects reuse of a key with a different request', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 1]]);
    $other = makeCart($this->a['tenant'], [[$this->product, 1]]);

    checkout($this->a['tenant'], $cart, key: 'reuse-key-0001')->assertCreated();
    checkout($this->a['tenant'], $other, key: 'reuse-key-0001')->assertStatus(422)->assertJsonPath('meta.errorCode', 'IDEMPOTENCY_KEY_REUSED');

    expect(Order::withoutTenantScope()->count())->toBe(1);
});

it('releases the key when an attempt fails so the client can retry', function () {
    $cart = makeCart($this->a['tenant'], [[$this->product, 5]]);
    $this->product->update(['stock' => 1]);

    checkout($this->a['tenant'], $cart, key: 'fail-key-00001')->assertStatus(409);
    expect(IdempotencyKey::withoutTenantScope()->count())->toBe(0);

    $this->product->update(['stock' => 10]);
    checkout($this->a['tenant'], $cart, key: 'fail-key-00001')->assertCreated();
});

it('scopes keys per tenant', function () {
    $theirs = Product::factory()->forTenant($this->b['tenant'])->create(['stock' => 5]);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$this->product, 1]]), key: 'shared-key-001')->assertCreated();
    checkout($this->b['tenant'], makeCart($this->b['tenant'], [[$theirs, 1]]), key: 'shared-key-001')->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Order::withoutTenantScope()->count())->toBe(2);
});

it('reports a concurrent in-flight request and recovers a stale one', function () {
    $service = app(IdempotencyService::class);
    app(TenantContext::class)->set($this->a['tenant']);
    $service->begin('checkout', 'inflight-key-1', 'hash');

    expect(fn () => $service->begin('checkout', 'inflight-key-1', 'hash'))
        ->toThrow(BaseApiException::class, 'still being processed');

    IdempotencyKey::query()->update(['created_at' => now()->subMinutes(10)]);
    expect($service->begin('checkout', 'inflight-key-1', 'hash')['replay'])->toBeNull();
});
