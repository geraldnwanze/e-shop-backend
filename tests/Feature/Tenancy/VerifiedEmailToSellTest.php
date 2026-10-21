<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    enableGateway($this->a['tenant']);
    fakePaystackInit();
    $this->product = Product::factory()->forTenant($this->a['tenant'])->create(['price' => 150000, 'stock' => 10]);
});

function unverify(User $user): void
{
    $user->forceFill(['email_verified_at' => null])->save();
}

function tryCheckout(): TestResponse
{
    return checkout(test()->a['tenant'], makeCart(test()->a['tenant'], [[test()->product, 1]]));
}

it('refuses checkout while the owner has not verified their email, and creates nothing', function () {
    unverify($this->a['owner']);

    tryCheckout()->assertForbidden()->assertJsonPath('meta.errorCode', 'STORE_NOT_ACCEPTING_ORDERS');

    expect(Order::withoutTenantScope()->count())->toBe(0)->and($this->product->fresh()->stock)->toBe(10);
    Notification::assertNothingSent();
});

it('accepts orders again as soon as the owner verifies', function () {
    unverify($this->a['owner']);
    tryCheckout()->assertForbidden();

    $this->a['owner']->markEmailAsVerified();

    tryCheckout()->assertCreated();
});

it('only counts the owner: a verified staff member does not open the store, an unverified one does not close it', function () {
    unverify($this->a['owner']);
    User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

    tryCheckout()->assertForbidden();

    $this->a['owner']->markEmailAsVerified();
    User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->unverified()->create();

    tryCheckout()->assertCreated();
});

it('does not let one store\'s unverified owner affect another store', function () {
    unverify($this->a['owner']);
    $product = Product::factory()->forTenant($this->b['tenant'])->create(['price' => 150000, 'stock' => 10]);

    checkout($this->b['tenant'], makeCart($this->b['tenant'], [[$product, 1]]))->assertCreated();
});

it('refuses to start a payment for a store that cannot sell', function () {
    $order = placedOrder($this->a['tenant']);
    unverify($this->a['owner']);

    initPayment($this->a['tenant'], $order)->assertForbidden()->assertJsonPath('meta.errorCode', 'STORE_NOT_ACCEPTING_ORDERS');

    $this->a['owner']->markEmailAsVerified();
    initPayment($this->a['tenant'], $order)->assertOk();
});

it('can be switched off by configuration', function () {
    config(['tenancy.require_verified_email_to_sell' => false]);
    unverify($this->a['owner']);

    tryCheckout()->assertCreated();
});

it('tells the storefront and the merchant whether the store is accepting orders', function () {
    http()->getJson('/api/v1/storefront/store', storefrontHeaders($this->a['tenant']))->assertOk()->assertJsonPath('data.acceptingOrders', true);
    $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/store')->assertOk()->assertJsonPath('data.acceptingOrders', true)->assertJsonPath('data.slug', 'icecream');

    unverify($this->a['owner']);

    http()->getJson('/api/v1/storefront/store', storefrontHeaders($this->a['tenant']))->assertOk()->assertJsonPath('data.acceptingOrders', false);
    $this->actingAs($this->a['owner']->fresh(), 'sanctum')->getJson('/api/v1/store')->assertOk()->assertJsonPath('data.acceptingOrders', false);
});

describe('platform admin override', function () {
    beforeEach(function () {
        $this->admin = User::factory()->platformAdmin()->create();
        unverify($this->a['owner']);
    });

    it('marks a merchant\'s email verified so their store can sell, and records it', function () {
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/users/{$this->a['owner']->id}/verify-email")
            ->assertOk()->assertJsonPath('data.emailVerified', true);

        expect($this->a['owner']->fresh()->hasVerifiedEmail())->toBeTrue();
        expect(AuditLog::query()->where('action', 'user.email_verified')->where('subject_id', $this->a['owner']->id)->where('actor_id', $this->admin->id)->count())->toBe(1);
        tryCheckout()->assertCreated();
    });

    it('is harmless when the email is already verified, and cannot target admins', function () {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/v1/admin/users/{$this->b['owner']->id}/verify-email")->assertOk()->assertJsonPath('data.emailVerified', true);
        $this->postJson("/api/v1/admin/users/{$this->admin->id}/verify-email")->assertForbidden();
    });

    it('is not available to merchants', function () {
        $this->actingAs($this->b['owner'], 'sanctum')->postJson("/api/v1/admin/users/{$this->a['owner']->id}/verify-email")->assertForbidden();
        expect($this->a['owner']->fresh()->hasVerifiedEmail())->toBeFalse();
    });
});
