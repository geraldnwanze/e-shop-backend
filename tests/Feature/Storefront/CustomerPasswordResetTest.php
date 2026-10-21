<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPasswordReset;
use App\Notifications\StoreMessageNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('x');
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->ha = storefrontHeaders($this->a['tenant']);
    $this->hb = storefrontHeaders($this->b['tenant']);
    $this->shopper = Customer::factory()->forTenant($this->a['tenant'])->create(['email' => 'ada@mail.test', 'first_name' => 'Ada']);
    $this->shopper->forceFill(['password' => 'old-password1'])->save();
});

/** @return array{0: string, 1: string} the email and token from the most recent reset email */
function lastResetLink(): array
{
    $found = null;

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'password_reset') {
                    $found = [$row['notifiable']->routes['mail'], $row['notification']->message['button']['url']];
                }
            }
        }
    }

    expect($found)->not->toBeNull();
    parse_str((string) parse_url($found[1], PHP_URL_QUERY), $query);

    return [$found[1], $query['token']];
}

function sentResetEmails(): int
{
    $count = 0;

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                $count += (int) ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'password_reset');
            }
        }
    }

    return $count;
}

function forgot(string $email, array $headers)
{
    return http()->postJson('/api/v1/storefront/auth/forgot-password', ['email' => $email], $headers);
}

function resetWith(string $email, string $token, string $password, array $headers, ?string $confirmation = null)
{
    return http()->postJson('/api/v1/storefront/auth/reset-password', ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password], $headers);
}

describe('asking for a link', function () {
    it('emails a link on the store\'s own address to a shopper with an account', function () {
        forgot('Ada@Mail.test', $this->ha)->assertOk();

        [$url, $token] = lastResetLink();

        expect($url)->toStartWith('http')->toContain('icecream.')->toContain('/account/reset-password?')->toContain('email=ada%40mail.test')
            ->and($token)->toHaveLength(64)
            ->and(CustomerPasswordReset::withoutTenantScope()->count())->toBe(1)
            ->and(CustomerPasswordReset::withoutTenantScope()->first()->token_hash)->not->toContain($token);
    });

    it('gives the same answer for unknown addresses and guests, and sends nothing to them', function () {
        $guest = Customer::factory()->forTenant($this->a['tenant'])->create(['email' => 'guest@mail.test', 'password' => null]);

        $known = forgot('ada@mail.test', $this->ha)->assertOk()->json();
        $unknown = forgot('nobody@mail.test', $this->ha)->assertOk()->json();
        $guestAnswer = forgot($guest->email, $this->ha)->assertOk()->json();

        expect($unknown['message'])->toBe($known['message'])->and($guestAnswer['message'])->toBe($known['message'])
            ->and(sentResetEmails())->toBe(1);
    });

    it('does not send a second link within a minute, but does after', function () {
        forgot('ada@mail.test', $this->ha);
        forgot('ada@mail.test', $this->ha);
        expect(sentResetEmails())->toBe(1);

        $this->travel(61)->seconds();
        forgot('ada@mail.test', $this->ha);

        expect(sentResetEmails())->toBe(2)->and(CustomerPasswordReset::withoutTenantScope()->count())->toBe(1);
    });

    it('limits how often one address can be asked about', function () {
        foreach (range(1, 3) as $i) {
            forgot("someone{$i}@mail.test", $this->ha)->assertOk();
        }

        foreach (range(1, 3) as $i) {
            forgot('flooded@mail.test', $this->ha);
        }

        forgot('flooded@mail.test', $this->ha)->assertStatus(429);
    });

    it('validates the email', function () {
        forgot('not-an-email', $this->ha)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['email']]]);
    });
});

describe('choosing a new password', function () {
    it('changes the password, uses up the link, signs the shopper out everywhere and records it', function () {
        $oldToken = $this->shopper->createToken('phone')->plainTextToken;
        forgot('ada@mail.test', $this->ha);
        [, $token] = lastResetLink();

        resetWith('ada@mail.test', $token, 'brand-new-pass1', $this->ha)->assertOk();

        http()->postJson('/api/v1/storefront/auth/login', ['email' => 'ada@mail.test', 'password' => 'brand-new-pass1'], $this->ha)->assertOk();
        http()->postJson('/api/v1/storefront/auth/login', ['email' => 'ada@mail.test', 'password' => 'old-password1'], $this->ha)->assertUnauthorized();
        http()->getJson('/api/v1/storefront/auth/me', storefrontHeaders($this->a['tenant'], $oldToken))->assertUnauthorized();
        resetWith('ada@mail.test', $token, 'another-pass-2', $this->ha)->assertUnprocessable();

        expect(CustomerPasswordReset::withoutTenantScope()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'auth.password_reset')->where('actor_id', $this->shopper->id)->where('actor_type', 'customer')->count())->toBe(1);
    });

    it('refuses a wrong, expired or someone else\'s link', function () {
        forgot('ada@mail.test', $this->ha);
        [, $token] = lastResetLink();

        resetWith('ada@mail.test', str_repeat('x', 64), 'brand-new-pass1', $this->ha)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['token']]]);
        resetWith('other@mail.test', $token, 'brand-new-pass1', $this->ha)->assertUnprocessable();

        $this->travel(61)->minutes();
        resetWith('ada@mail.test', $token, 'brand-new-pass1', $this->ha)->assertUnprocessable();

        expect(Customer::withoutTenantScope()->find($this->shopper->id)->password)->not->toBeNull();
        http()->postJson('/api/v1/storefront/auth/login', ['email' => 'ada@mail.test', 'password' => 'old-password1'], $this->ha)->assertOk();
    });

    it('never lets a link from one store open an account at another that uses the same email', function () {
        $other = Customer::factory()->forTenant($this->b['tenant'])->create(['email' => 'ada@mail.test']);
        $other->forceFill(['password' => 'fashion-pass-1'])->save();

        forgot('ada@mail.test', $this->ha);
        [, $token] = lastResetLink();

        resetWith('ada@mail.test', $token, 'hijacked-pass-1', $this->hb)->assertUnprocessable();

        http()->postJson('/api/v1/storefront/auth/login', ['email' => 'ada@mail.test', 'password' => 'fashion-pass-1'], $this->hb)->assertOk();
    });

    it('applies the same password rules as sign-up', function () {
        forgot('ada@mail.test', $this->ha);
        [, $token] = lastResetLink();

        resetWith('ada@mail.test', $token, 'short', $this->ha)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['password']]]);
        resetWith('ada@mail.test', $token, 'brand-new-pass1', $this->ha, 'different-pass-9')->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['password']]]);

        resetWith('ada@mail.test', $token, 'brand-new-pass1', $this->ha)->assertOk();
    });
});
