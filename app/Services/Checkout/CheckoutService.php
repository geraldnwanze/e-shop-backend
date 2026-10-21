<?php

namespace App\Services\Checkout;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Exceptions\BaseApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Store;
use App\Services\Inventory\InventoryService;
use App\Services\Shipping\ShippingService;
use App\Services\Themes\StoreCustomizationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a cart into an order atomically. Prices, discounts, shipping, totals and
 * statuses are all computed here from database state, never from client input.
 */
class CheckoutService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderNumberGenerator $numbers,
        private readonly StoreCustomizationService $customization,
        private readonly ShippingService $shipping,
    ) {}

    /**
     * @param  array{customer: array{email: string, first_name: string, last_name: string, phone?: ?string}, shipping_address: array<string, mixed>, billing_address?: ?array<string, mixed>, discount_code?: ?string, notes?: ?string}  $data
     */
    public function placeOrder(Cart $cart, array $data, ?Customer $authenticated = null): Order
    {
        if (! app(TenantContext::class)->requireTenant()->acceptsOrders()) {
            throw BaseApiException::storeNotAcceptingOrders();
        }

        $store = Store::query()->firstOrFail();
        $settings = $this->customization->settings($store)->settings;

        if ($authenticated === null && ! ($settings['checkout']['allow_guest_checkout'] ?? true)) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'Please sign in to check out.');
        }

        return DB::transaction(function () use ($cart, $data, $authenticated, $store, $settings): Order {
            $cart = Cart::query()->whereKey($cart->getKey())->lockForUpdate()->firstOrFail();

            if ($cart->converted_at !== null) {
                throw BaseApiException::conflict('This cart has already been checked out.');
            }

            $items = $cart->items()->with(['product.variants', 'variant'])->get();

            if ($items->isEmpty()) {
                throw new BaseApiException(ApiErrorCodeEnum::EmptyCart, 'Your cart is empty.');
            }

            $this->assertPurchasable($items);

            $subtotal = $items->sum(fn (CartItem $i) => $i->lineTotal());
            [$discount, $discountTotal] = $this->applyDiscount($data['discount_code'] ?? null, $subtotal);
            // Stores with delivery zones charge by address and chosen method; stores without keep their single flat rate.
            $delivery = $this->shipping->isConfigured() ? $this->shipping->resolve($data['shipping_address'], $data['shipping_rate_id'] ?? null, $subtotal) : null;
            $shipping = $delivery !== null ? $delivery['price'] : (int) ($settings['checkout']['shipping_flat_rate'] ?? 0);

            $customer = $authenticated ?? $this->guestCustomer($data['customer']);

            if ($data['customer']['accepts_marketing'] ?? false) {
                $customer->optInToMarketing();
            }
            $contact = $authenticated
                ? ['email' => $authenticated->email, 'name' => $authenticated->fullName(), 'phone' => $data['customer']['phone'] ?? $authenticated->phone]
                : ['email' => $data['customer']['email'], 'name' => trim($data['customer']['first_name'].' '.$data['customer']['last_name']), 'phone' => $data['customer']['phone'] ?? null];

            $order = new Order([
                'order_number' => $this->numbers->next(),
                'customer_id' => $customer->getKey(),
                'cart_id' => $cart->getKey(),
                'placed_authenticated' => $authenticated !== null,
                'customer_email' => $contact['email'],
                'customer_name' => $contact['name'],
                'customer_phone' => $contact['phone'],
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'currency' => $store->currency,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shipping,
                'shipping_method' => $delivery['rate']->name ?? null,
                'shipping_rate_id' => $delivery['rate']->id ?? null,
                'tax_total' => 0,
                'total' => $subtotal - $discountTotal + $shipping,
                'discount_id' => $discount?->getKey(),
                'discount_code' => $discount?->code,
                'shipping_address' => $data['shipping_address'],
                'billing_address' => $data['billing_address'] ?? $data['shipping_address'],
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);
            $order->save();

            // Lock-order by id to avoid deadlocks between concurrent checkouts.
            foreach ($items->sortBy(fn (CartItem $i) => $i->variant_id ?? $i->product_id) as $item) {
                $this->inventory->reserve($item->product, $item->variant, $item->quantity, $order);

                $order->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'name' => $item->product->name,
                    'variant_name' => $item->variant?->name,
                    'sku' => $item->variant?->sku ?? $item->product->sku,
                    'unit_price' => $item->unitPrice(),
                    'quantity' => $item->quantity,
                    'total' => $item->lineTotal(),
                ]);
            }

            $cart->forceFill(['converted_at' => now()])->save();

            event(new OrderPlaced($order->tenant_id, $order->getKey()));

            return $order->load('items');
        });
    }

    /**
     * @param  Collection<int, CartItem>  $items
     */
    private function assertPurchasable($items): void
    {
        foreach ($items as $item) {
            if (! $item->product->isPurchasable() || ($item->product->hasVariants() && $item->variant === null)) {
                throw ValidationException::withMessages(['cart' => ["\"{$item->product->name}\" is no longer available."]]);
            }
        }
    }

    /**
     * @return array{0: ?Discount, 1: int}
     */
    private function applyDiscount(?string $code, int $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return [null, 0];
        }

        $discount = Discount::query()->where('code', strtoupper(trim($code)))->lockForUpdate()->first();

        if ($discount === null || ! $discount->isRedeemableFor($subtotal)) {
            throw ValidationException::withMessages(['discount_code' => ['This discount code is invalid or cannot be applied.']]);
        }

        // Atomic usage claim: two concurrent checkouts cannot exceed max_uses.
        $claimed = Discount::query()->whereKey($discount->getKey())
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
            ->increment('used_count');

        if ($claimed === 0) {
            throw ValidationException::withMessages(['discount_code' => ['This discount code has been fully redeemed.']]);
        }

        return [$discount, $discount->amountFor($subtotal)];
    }

    /**
     * Guest checkout never alters an existing account's profile.
     *
     * @param  array{email: string, first_name: string, last_name: string, phone?: ?string}  $data
     */
    private function guestCustomer(array $data): Customer
    {
        $email = strtolower(trim($data['email']));
        $customer = Customer::withTrashed()->where('email', $email)->first();

        if ($customer === null) {
            $customer = new Customer(['email' => $email, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'phone' => $data['phone'] ?? null]);
            $customer->save();
        } elseif ($customer->trashed()) {
            $customer->restore();
        }

        return $customer;
    }
}
