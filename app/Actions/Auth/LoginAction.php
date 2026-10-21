<?php

namespace App\Actions\Auth;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Exceptions\TenantSuspendedException;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;

class LoginAction
{
    /**
     * @return array{user: User, token: string}
     */
    public function execute(string $email, string $password, string $deviceName = 'api'): array
    {
        $user = User::query()->where('email', $email)->first();

        // Always hash-compare to keep timing uniform whether or not the user exists.
        $valid = Hash::check($password, $user?->password ?? Hash::make('timing-equalizer'));

        if ($user === null || ! $valid) {
            Audit::record('auth.login_failed', changes: ['email' => $email], tenantId: $user?->tenant_id, subjectLabel: $email);

            throw BaseApiException::invalidCredentials();
        }

        if ($user->isSuspended()) {
            Audit::record('auth.login_blocked', changes: ['reason' => 'account suspended'], tenantId: $user->tenant_id, actor: $user);

            throw new BaseApiException(ApiErrorCodeEnum::AccountSuspended, 'This account is suspended. Contact support.');
        }

        if ($user->tenant_id !== null && ! $user->tenant->isActive()) {
            Audit::record('auth.login_blocked', changes: ['reason' => 'store suspended'], tenantId: $user->tenant_id, actor: $user);

            throw new TenantSuspendedException('Your store is suspended. Contact support.');
        }

        $expiration = config('sanctum.expiration');
        $token = $user->createToken($deviceName, ['*'], $expiration ? now()->addMinutes((int) $expiration) : null);

        Audit::record('auth.login', changes: ['device' => $deviceName], tenantId: $user->tenant_id, actor: $user);

        return ['user' => $user, 'token' => $token->plainTextToken];
    }
}
