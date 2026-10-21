<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Actions\Storefront\LoginCustomerAction;
use App\Actions\Storefront\RegisterCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Storefront\CustomerForgotPasswordRequest;
use App\Http\Requests\Storefront\CustomerRegisterRequest;
use App\Http\Requests\Storefront\CustomerResetPasswordRequest;
use App\Http\Resources\CustomerResource;
use App\Services\Auth\CustomerPasswordResetService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Storefront: Customer accounts', weight: 13)]
class CustomerAuthController extends Controller
{
    public function register(CustomerRegisterRequest $request, RegisterCustomerAction $register, LoginCustomerAction $login): JsonResponse
    {
        $customer = $register->execute($request->validated());
        $session = $login->execute($customer->email, $request->validated('password'));

        return ApiResponse::created(['customer' => CustomerResource::make($customer), 'token' => $session['token']], 'Account created.');
    }

    public function login(LoginRequest $request, LoginCustomerAction $login): JsonResponse
    {
        $session = $login->execute($request->validated('email'), $request->validated('password'));

        return ApiResponse::success(['customer' => CustomerResource::make($session['customer']), 'token' => $session['token']], 'Login successful.');
    }

    /**
     * Email a reset link to a shopper who has an account here. The reply is the same whether or not the address is known.
     */
    public function forgotPassword(CustomerForgotPasswordRequest $request, CustomerPasswordResetService $resets): JsonResponse
    {
        $resets->request($request->validated('email'));

        return ApiResponse::noContent('If that email has an account, a reset link is on its way.');
    }

    public function resetPassword(CustomerResetPasswordRequest $request, CustomerPasswordResetService $resets): JsonResponse
    {
        $resets->reset($request->validated('email'), $request->validated('token'), $request->validated('password'));

        return ApiResponse::noContent('Your password has been changed. You can sign in now.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::noContent('Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(CustomerResource::make($request->user()));
    }
}
