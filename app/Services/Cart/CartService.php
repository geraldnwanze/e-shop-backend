<?php

namespace App\Services\Cart;

use App\Exceptions\BaseApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class CartService
{
    public const MAX_LINE_QUANTITY = 100;

    public function create(?Customer $customer = null): Cart
    {
        $cart = new Cart(['currency' => Store::query()->value('currency') ?? 'NGN']);
        $cart->customer_id = $customer?->getKey();
        $cart->save();

        return $cart;
    }

    /**
     * Remember where to send a reminder if this cart is left behind. A new address re-arms the reminder.
     */
    public function captureEmail(Cart $cart, string $email): void
    {
        $this->assertOpen($cart);

        $email = mb_strtolower(trim($email));

        if ($cart->email === $email) {
            return;
        }

        $cart->forceFill(['email' => $email, 'reminder_sent_at' => null, 'second_reminder_sent_at' => null])->save();
    }

    public function addItem(Cart $cart, string $productUuid, ?string $variantUuid, int $quantity): CartItem
    {
        $this->assertOpen($cart);

        $product = Product::query()->with('variants')->where('id', $productUuid)->first();

        if ($product === null || ! $product->isPurchasable()) {
            throw ValidationException::withMessages(['product_id' => ['This product is not available.']]);
        }

        $variant = $this->resolveVariant($product, $variantUuid);

        $item = $cart->items()->firstOrNew(['product_id' => $product->getKey(), 'variant_id' => $variant?->getKey()]);
        $newQuantity = ($item->exists ? $item->quantity : 0) + $quantity;

        $this->assertQuantity($product, $variant, $newQuantity);

        $item->quantity = $newQuantity;
        $item->save();

        return $item;
    }

    /**
     * @return array{code: string, discountTotal: int, subtotal: int, total: int}
     */
    public function previewDiscount(Cart $cart, string $code): array
    {
        $this->assertOpen($cart);

        $subtotal = (int) $cart->items()->with(['product', 'variant'])->get()->sum(fn (CartItem $item) => $item->lineTotal());
        $discount = Discount::query()->where('code', strtoupper(trim($code)))->first();

        if ($discount === null || ! $discount->isRedeemableFor($subtotal)) {
            throw ValidationException::withMessages(['code' => ['This discount code is invalid or cannot be applied.']]);
        }

        $amount = $discount->amountFor($subtotal);

        return ['code' => $discount->code, 'discountTotal' => $amount, 'subtotal' => $subtotal, 'total' => $subtotal - $amount];
    }

    public function updateItem(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        $this->assertOpen($cart);
        $this->assertQuantity($item->product, $item->variant, $quantity);

        $item->update(['quantity' => $quantity]);

        return $item;
    }

    public function removeItem(Cart $cart, CartItem $item): void
    {
        $this->assertOpen($cart);
        $item->delete();
    }

    private function resolveVariant(Product $product, ?string $variantUuid): ?ProductVariant
    {
        if ($product->hasVariants()) {
            $variant = $variantUuid === null ? null : $product->variants->firstWhere('id', $variantUuid);

            if ($variant === null) {
                throw ValidationException::withMessages(['variant_id' => ['Select a valid option for this product.']]);
            }

            return $variant;
        }

        if ($variantUuid !== null) {
            throw ValidationException::withMessages(['variant_id' => ['This product has no options.']]);
        }

        return null;
    }

    private function assertQuantity(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_LINE_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be between 1 and '.self::MAX_LINE_QUANTITY.'.']]);
        }

        if ($product->track_inventory) {
            $available = ($variant ?? $product)->stock;

            if ($quantity > $available) {
                throw new InsufficientStockException($product->name, $available);
            }
        }
    }

    private function assertOpen(Cart $cart): void
    {
        if ($cart->converted_at !== null) {
            throw BaseApiException::conflict('This cart has already been checked out.');
        }
    }
}
