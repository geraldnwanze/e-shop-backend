<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Customer;
use App\Services\Checkout\CheckoutService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Storefront: Checkout & payments', weight: 12)]
class CheckoutController extends Controller
{
    /**
     * Idempotent (see IdempotentMiddleware). Order totals, statuses and payment
     * state are computed server-side; the client only identifies the cart.
     */
    public function store(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $cart = Cart::query()->where('id', $request->validated('cart_id'))->firstOrFail();
        $actor = $request->user('sanctum');
        $customer = $actor instanceof Customer ? $actor : null;

        if ($cart->customer_id !== null && $cart->customer_id !== $customer?->getKey()) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This cart belongs to another account.');
        }

        $order = $checkout->placeOrder($cart, $request->safe()->except('cart_id'), $customer);

        return ApiResponse::created(OrderResource::make($order), 'Order placed.');
    }
}
