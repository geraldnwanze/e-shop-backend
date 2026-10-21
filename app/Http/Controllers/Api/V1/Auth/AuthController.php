<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginAction;
use App\Actions\Auth\RegisterMerchantAction;
use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Platform\PlatformSettings;
use App\Support\ApiResponse;
use App\Support\Audit;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Authentication', weight: 1)]
class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterMerchantAction $action, LoginAction $login, PlatformSettings $settings): JsonResponse
    {
        if (! $settings->get('registration.enabled')) {
            throw new BaseApiException(ApiErrorCodeEnum::RegistrationClosed, 'New store registration is currently closed.');
        }

        $user = $action->execute($request->safe()->only(['name', 'email', 'password', 'store_name', 'store_slug']));

        $session = $login->execute($user->email, $request->validated('password'), 'registration');

        return ApiResponse::created([
            'user' => UserResource::make($user->load('tenant.store')),
            'token' => $session['token'],
        ], 'Registration successful. Please verify your email address.');
    }

    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $session = $action->execute(
            $request->validated('email'),
            $request->validated('password'),
            $request->validated('device_name') ?? 'api',
        );

        return ApiResponse::success([
            'user' => UserResource::make($session['user']->load('tenant')),
            'token' => $session['token'],
        ], 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        Audit::record('auth.logout');
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::noContent('Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(UserResource::make($request->user()->load('tenant')));
    }
}
