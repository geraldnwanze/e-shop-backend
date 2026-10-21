<?php

use App\Models\Domain;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Merchant',
        'email' => 'Jane@Example.com',
        'password' => 'secret-pass1',
        'password_confirmation' => 'secret-pass1',
        'store_name' => 'Ice Cream Co',
        'store_slug' => 'icecream',
    ], $overrides);
}

it('registers a merchant with tenant, store and subdomain in one go', function () {
    Notification::fake();

    $response = $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertCreated()
        ->assertJsonStructure(['status', 'statusCode', 'message', 'data' => ['user', 'token'], 'meta', 'requestId'])
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.user.email', 'jane@example.com')
        ->assertJsonPath('data.user.role', 'merchant_owner')
        ->assertJsonPath('data.user.emailVerified', false);

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($user->tenant->slug)->toBe('icecream')
        ->and(Store::withoutTenantScope()->where('tenant_id', $user->tenant_id)->count())->toBe(1)
        ->and(Domain::withoutTenantScope()->where('domain', 'icecream.mydomain.test')->value('is_verified'))->toBeTrue();

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
    expect($response->headers->get('X-Request-Id'))->toBe($response->json('requestId'));
});

it('derives the slug from the store name when omitted', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload(['store_slug' => '', 'store_name' => 'Fancy Fashion']))
        ->assertCreated();

    expect(Tenant::where('slug', 'fancy-fashion')->exists())->toBeTrue();
});

it('ignores client-supplied tenant_id and role on registration', function () {
    Notification::fake();
    $other = Tenant::factory()->create();

    $this->postJson('/api/v1/auth/register', registrationPayload(['tenant_id' => $other->id, 'role' => 'platform_admin']))
        ->assertCreated()->assertJsonPath('data.user.role', 'merchant_owner');

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    expect($user->tenant_id)->not->toBe($other->id);
});

it('validates registration input', function (array $overrides, string $field) {
    $this->postJson('/api/v1/auth/register', registrationPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('meta.errorCode', 'VALIDATION_FAILED')
        ->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'reserved slug' => [['store_slug' => 'api'], 'store_slug'],
    'invalid slug' => [['store_slug' => 'bad_slug!'], 'store_slug'],
    'weak password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'bad email' => [['email' => 'nope'], 'email'],
]);

it('returns field errors in the standard envelope', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload(['store_slug' => 'api']))
        ->assertUnprocessable()
        ->assertJsonPath('status', false)
        ->assertJsonPath('meta.errorCode', 'VALIDATION_FAILED')
        ->assertJsonStructure(['meta' => ['errors' => ['store_slug']]]);
});

it('rejects duplicate emails and slugs', function () {
    Notification::fake();
    $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    $this->postJson('/api/v1/auth/register', registrationPayload(['store_name' => 'Other']))
        ->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['email', 'store_slug']]]);
});

it('logs in and returns a bearer token usable on /me', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);

    $token = $this->postJson('/api/v1/auth/login', ['email' => 'OWNER@example.com', 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.user.id', $user->id)->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.email', 'owner@example.com')
        ->assertJsonPath('data.tenant.slug', $user->tenant->slug);
});

it('rejects invalid credentials without revealing which part was wrong', function () {
    User::factory()->create(['email' => 'owner@example.com']);

    $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'wrong'])
        ->assertUnauthorized()->assertJsonPath('meta.errorCode', 'INVALID_CREDENTIALS');
    $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'password'])
        ->assertUnauthorized()->assertJsonPath('meta.errorCode', 'INVALID_CREDENTIALS');
});

it('blocks login for users of suspended tenants', function () {
    $user = User::factory()->forTenant(Tenant::factory()->suspended()->create())->create();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertForbidden()->assertJsonPath('meta.errorCode', 'TENANT_SUSPENDED');
});

it('requires authentication for /me', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('meta.errorCode', 'UNAUTHENTICATED');
});

it('logs out by revoking only the current token', function () {
    $user = User::factory()->create();
    $first = $user->createToken('a')->plainTextToken;
    $user->createToken('b');

    $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();

    expect($user->tokens()->count())->toBe(1);
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('sends a password reset link and does not reveal unknown emails', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

    Notification::assertSentTo($user, QueuedResetPassword::class);
    Notification::assertCount(1);
});

it('resets the password with a valid token and revokes existing tokens', function () {
    $user = User::factory()->create();
    $user->createToken('old');
    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'brand-new-pass1'])->assertOk();
});

it('rejects an invalid reset token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'bogus', 'email' => $user->email,
        'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1',
    ])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['email']]]);
});

it('verifies email through a signed link and redirects to the frontend', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);

    $this->get($url)->assertRedirect('http://app.mydomain.test/email-verified');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects tampered or wrongly-hashed verification links', function () {
    $user = User::factory()->unverified()->create();

    $this->getJson("/api/v1/auth/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();

    $badHash = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1('someone-else@example.com'),
    ]);
    $this->getJson($badHash)->assertForbidden()->assertJsonPath('meta.errorCode', 'INVALID_SIGNATURE');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification email', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});

it('throttles repeated login attempts', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'a@b.com', 'password' => 'x'])
        ->assertStatus(429)->assertJsonPath('meta.errorCode', 'TOO_MANY_REQUESTS');
});

it('treats a bearer token with a non-uuid id as unauthenticated instead of failing', function () {
    foreach (['123|'.str_repeat('a', 40), 'not-a-token-id|abc'] as $stale) {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$stale])->assertUnauthorized();
    }
});

it('still authenticates a valid token issued by the uuid token model', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('test')->plainTextToken;

    expect(explode('|', $plain)[0])->toBeUuid();

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertOk();
});
