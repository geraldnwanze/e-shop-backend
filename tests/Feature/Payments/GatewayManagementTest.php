<?php

use App\Enums\UserRole;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->actingAs($this->a['owner'], 'sanctum');
});

it('saves credentials write-only and never returns secrets', function () {
    $response = $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_supersecret9876', 'public_key' => 'pk_test_abc', 'is_default' => true])
        ->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.isActive', true)->assertJsonPath('data.secretKeyHint', '••••9876')
        ->assertJsonPath('data.publicKey', 'pk_test_abc')
        ->assertJsonPath('data.webhookUrl', route('api.v1.webhooks.payments', ['provider' => 'paystack', 'tenant' => $this->a['tenant']->id]));

    expect($response->getContent())->not->toContain('supersecret');
    $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJsonCount(2, 'data');
    expect($this->getJson('/api/v1/payment-gateways')->getContent())->not->toContain('supersecret');
});

it('encrypts secrets at rest', function () {
    $this->putJson('/api/v1/payment-gateways/flutterwave', ['secret_key' => 'FLWSECK_TEST-realsecret1', 'webhook_secret' => 'my-webhook-hash'])->assertOk();

    $raw = DB::table('payment_gateways')->first();
    expect($raw->secret_key)->not->toContain('realsecret1')->and($raw->webhook_secret)->not->toContain('my-webhook-hash');
    expect(PaymentGateway::withoutTenantScope()->first()->secret_key)->toBe('FLWSECK_TEST-realsecret1');
    expect(PaymentGateway::withoutTenantScope()->first()->toArray())->not->toHaveKeys(['secret_key', 'webhook_secret']);
});

it('validates key formats and required fields', function (string $provider, array $payload, string $field) {
    $this->putJson("/api/v1/payment-gateways/{$provider}", $payload)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'paystack wrong format' => ['paystack', ['secret_key' => 'not-a-key'], 'secret_key'],
    'paystack missing' => ['paystack', [], 'secret_key'],
    'flutterwave wrong format' => ['flutterwave', ['secret_key' => 'sk_test_abc', 'webhook_secret' => 'longenough'], 'secret_key'],
    'flutterwave needs webhook secret' => ['flutterwave', ['secret_key' => 'FLWSECK_TEST-abc'], 'webhook_secret'],
]);

it('rejects unknown providers', function () {
    $this->putJson('/api/v1/payment-gateways/stripe', ['secret_key' => 'x'])->assertNotFound();
});

it('updates without resending the secret and toggles active', function () {
    $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_original1'])->assertOk();
    $this->putJson('/api/v1/payment-gateways/paystack', ['is_active' => false])->assertOk()->assertJsonPath('data.isActive', false)->assertJsonPath('data.secretKeyHint', '••••nal1');
});

it('keeps a single default gateway', function () {
    $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_aaa111', 'is_default' => true])->assertOk();
    $this->putJson('/api/v1/payment-gateways/flutterwave', ['secret_key' => 'FLWSECK_TEST-bbb', 'webhook_secret' => 'hash-secret-1', 'is_default' => true])->assertOk();

    expect(PaymentGateway::withoutTenantScope()->where('is_default', true)->pluck('provider')->map->value->all())->toBe(['flutterwave']);
});

it('removes a gateway', function () {
    $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_aaa111'])->assertOk();

    $this->deleteJson('/api/v1/payment-gateways/paystack')->assertOk();

    $this->getJson('/api/v1/payment-gateways')->assertJsonPath('data.0.configured', false);
});

it('is restricted to the owner', function () {
    foreach ([UserRole::MerchantAdmin, UserRole::MerchantStaff, UserRole::Customer] as $role) {
        $user = User::factory()->forTenant($this->a['tenant'], $role)->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payment-gateways')->assertForbidden();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_aaa111'])->assertForbidden();
    }
    expect(PaymentGateway::withoutTenantScope()->count())->toBe(0);
});

it('isolates gateways between tenants', function () {
    enableGateway($this->a['tenant']);

    $this->actingAs($this->b['owner'], 'sanctum')->getJson('/api/v1/payment-gateways')->assertJsonPath('data.0.configured', false);
    $this->putJson('/api/v1/payment-gateways/paystack', ['secret_key' => 'sk_test_bbb222'])->assertOk();

    expect(PaymentGateway::withoutTenantScope()->count())->toBe(2)
        ->and(PaymentGateway::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->first()->secret_key)->toBe('sk_test_abc123');
});
