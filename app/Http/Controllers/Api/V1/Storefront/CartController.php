<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CartContactRequest;
use App\Http\Requests\Storefront\CartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Services\Cart\CartService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Storefront: Cart', weight: 11)]
class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function store(Request $request): JsonResponse
    {
        $customer = $request->user('sanctum');

        return ApiResponse::created(
            $this->present($this->carts->create($customer instanceof Customer ? $customer : null)),
            'Cart created.',
        );
    }

    public function show(Request $request, Cart $cart): JsonResponse
    {
        $this->guard($request, $cart);

        if ($cart->converted_at !== null) {
            throw BaseApiException::conflict('This cart has already been checked out.');
        }

        return ApiResponse::success($this->present($cart));
    }

    /**
     * Save the shopper's email against the cart as soon as they type it at checkout, so a reminder can be sent if they leave.
     */
    public function contact(CartContactRequest $request, Cart $cart): JsonResponse
    {
        $this->guard($request, $cart);

        $this->carts->captureEmail($cart, $request->validated('email'));

        return ApiResponse::success(null, 'Saved.');
    }

    public function addItem(CartItemRequest $request, Cart $cart): JsonResponse
    {
        $this->guard($request, $cart);

        $this->carts->addItem($cart, $request->validated('product_id'), $request->validated('variant_id'), $request->validated('quantity'));

        return ApiResponse::success($this->present($cart), 'Item added.');
    }

    /**
     * Show what a discount code would take off this cart, without using the code up. The real
     * check (and the usage count) happens again at checkout.
     */
    public function previewDiscount(Request $request, Cart $cart): JsonResponse
    {
        $this->guard($request, $cart);

        $code = $request->validate(['code' => ['required', 'string', 'max:40']])['code'];

        return ApiResponse::success($this->carts->previewDiscount($cart, $code));
    }

    public function updateItem(CartItemRequest $request, Cart $cart, CartItem $item): JsonResponse
    {
        $this->guard($request, $cart);
        $this->assertItemInCart($cart, $item);

        $this->carts->updateItem($cart, $item, $request->validated('quantity'));

        return ApiResponse::success($this->present($cart), 'Cart updated.');
    }

    public function removeItem(Request $request, Cart $cart, CartItem $item): JsonResponse
    {
        $this->guard($request, $cart);
        $this->assertItemInCart($cart, $item);

        $this->carts->removeItem($cart, $item);

        return ApiResponse::success($this->present($cart), 'Item removed.');
    }

    /**
     * A cart is reachable only by whoever knows its UUID (guest carts) or, for
     * customer carts, by that authenticated customer. Tenant scoping on binding
     * already prevents any cross-store access.
     */
    private function guard(Request $request, Cart $cart): void
    {
        if ($cart->customer_id !== null) {
            $actor = $request->user('sanctum');

            if (! $actor instanceof Customer || $actor->getKey() !== $cart->customer_id) {
                throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This cart belongs to another account.');
            }
        }
    }

    private function assertItemInCart(Cart $cart, CartItem $item): void
    {
        abort_unless($item->cart_id === $cart->getKey(), 404);
    }

    private function present(Cart $cart): CartResource
    {
        return CartResource::make($cart->refresh()->load(['items.product.images.media', 'items.variant']));
    }
}
