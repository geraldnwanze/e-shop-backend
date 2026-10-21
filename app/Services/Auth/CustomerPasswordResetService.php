<?php

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\CustomerPasswordReset;
use App\Notifications\StoreMessageNotification;
use App\Support\Audit;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password" for a store's shoppers. Works inside the store resolved from the hostname, so a link
 * issued by one store can never open an account at another.
 */
class CustomerPasswordResetService
{
    /** A new link is not sent more than once in this many seconds for the same address. */
    private const RESEND_AFTER_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Email a reset link when this address has an account. Says nothing either way, so the form cannot be
     * used to find out who shops here.
     */
    public function request(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $customer = Customer::query()->where('email', $email)->first();

        if ($customer === null || ! $customer->hasAccount()) {
            return;
        }

        $existing = CustomerPasswordReset::query()->where('email', $email)->first();

        if ($existing !== null && $existing->created_at->gt(now()->subSeconds(self::RESEND_AFTER_SECONDS))) {
            return;
        }

        $token = Str::random(64);

        CustomerPasswordReset::query()->where('email', $email)->delete();
        CustomerPasswordReset::query()->create(['email' => $email, 'token_hash' => hash('sha256', $token), 'created_at' => now()]);

        $tenant = $this->context->requireTenant();
        $mail = StoreMailContext::current();
        $minutes = $this->lifetimeMinutes();
        $first = trim($customer->first_name) !== '' ? trim($customer->first_name) : 'there';

        Notification::route('mail', $email)->notify(new StoreMessageNotification('password_reset', 'Reset your password', [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => 'Choose a new password for your account.',
            'heading' => 'Reset your password',
            'intro' => "Hi {$first}, we got a request to reset the password for your {$mail['store']} account. Choose a new one with the button below.",
            'button' => ['label' => 'Choose a new password', 'url' => StorefrontUrl::for($tenant, '/account/reset-password?'.http_build_query(['email' => $email, 'token' => $token]))],
            'outro' => "This link works for {$minutes} minutes and only once. If you did not ask for it, you can ignore this email: your password stays as it is.",
            'reply_to' => $mail['supportEmail'],
        ]));
    }

    /**
     * Set the new password. The link is used up, and every device the shopper was signed in on is signed out.
     *
     * @throws ValidationException when the link is wrong, already used or too old
     */
    public function reset(string $email, string $token, string $password): void
    {
        $email = mb_strtolower(trim($email));
        $invalid = ValidationException::withMessages(['token' => ['This reset link is invalid or has expired. Ask for a new one.']]);

        DB::transaction(function () use ($email, $token, $password, $invalid): void {
            $row = CustomerPasswordReset::query()->where('email', $email)->lockForUpdate()->first();

            if ($row === null || $row->created_at->lt(now()->subMinutes($this->lifetimeMinutes())) || ! hash_equals($row->token_hash, hash('sha256', $token))) {
                throw $invalid;
            }

            $customer = Customer::query()->where('email', $email)->first();

            if ($customer === null || ! $customer->hasAccount()) {
                throw $invalid;
            }

            $customer->forceFill(['password' => $password])->save();
            $customer->tokens()->delete();
            CustomerPasswordReset::query()->where('email', $email)->delete();

            Audit::record('auth.password_reset', changes: ['via' => 'reset link'], tenantId: $customer->tenant_id, actor: $customer);
        });
    }

    private function lifetimeMinutes(): int
    {
        return max(5, (int) config('auth.customer_reset_minutes', 60));
    }
}
