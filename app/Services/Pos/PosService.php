<?php

namespace App\Services\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Exceptions\BaseApiException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Checkout\OrderNumberGenerator;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * In-person sales rung up by staff. The shopper is standing there, so a sale is paid and handed
 * over in one step: the order is created PAID and COMPLETED, stock is taken immediately, and no
 * payment provider is involved (the cashier confirms the money).
 */
class PosService
{
    public function __construct(
        private readonly OrderNumberGenerator $numbers,
        private readonly InventoryService $inventory,
        private readonly PosShiftService $shifts,
    ) {}

    /**
     * @param  array{items: list<array{product_id: string, variant_id?: ?string, quantity: int}>, payment_method: string, amount_tendered?: ?int, discount_amount?: ?int, customer?: array{name?: ?string, email?: ?string, phone?: ?string}, notes?: ?string}  $data
     */
    public function sell(User $cashier, array $data): Order
    {
        $method = PosPaymentMethod::from($data['payment_method']);
        $store = Store::query()->firstOrFail();

        return DB::transaction(function () use ($cashier, $data, $method, $store): Order {
            $lines = $this->resolveLines($data['items']);
            $subtotal = array_sum(array_map(fn (array $l) => $l['unit_price'] * $l['quantity'], $lines));
            $discount = (int) ($data['discount_amount'] ?? 0);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount_amount' => ['The discount cannot be more than the sale total.']]);
            }

            $total = $subtotal - $discount;
            $tendered = $method === PosPaymentMethod::Cash ? (int) ($data['amount_tendered'] ?? $total) : null;

            if ($tendered !== null && $tendered < $total) {
                throw ValidationException::withMessages(['amount_tendered' => ['The cash received is less than the total.']]);
            }

            $customer = $data['customer'] ?? [];
            $email = mb_strtolower(trim((string) ($customer['email'] ?? '')));

            $order = new Order([
                'order_number' => $this->numbers->next(),
                'customer_id' => $email !== '' ? Customer::query()->where('email', $email)->value('id') : null,
                'customer_email' => $email,
                'customer_name' => trim((string) ($customer['name'] ?? '')) ?: 'Walk-in customer',
                'customer_phone' => ($customer['phone'] ?? null) ?: null,
                'status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'currency' => $store->currency,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'shipping_total' => 0,
                'tax_total' => 0,
                'total' => $total,
                'shipping_address' => [],
                'billing_address' => [],
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);
            $order->forceFill([
                'channel' => 'pos',
                'payment_method' => $method->value,
                'amount_tendered' => $tendered,
                'cashier_id' => $cashier->getKey(),
                'pos_shift_id' => $this->shifts->current($cashier)?->getKey(),
            ])->save();

            // Take stock in a stable order so two registers selling at once cannot deadlock.
            foreach (collect($lines)->sortBy(fn (array $l) => $l['variant']?->getKey() ?? $l['product']->getKey()) as $line) {
                $this->inventory->reserve($line['product'], $line['variant'], $line['quantity'], $order);

                $order->items()->create([
                    'product_id' => $line['product']->getKey(),
                    'variant_id' => $line['variant']?->getKey(),
                    'name' => $line['product']->name,
                    'variant_name' => $line['variant']?->name,
                    'sku' => $line['variant']?->sku ?? $line['product']->sku,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'total' => $line['unit_price'] * $line['quantity'],
                ]);
            }

            return $order->load('items');
        });
    }

    /**
     * Cancel a mistaken in-person sale: the stock goes back on the shelf and the sale stops counting
     * as revenue. Online orders are cancelled and refunded through their payment instead.
     */
    public function void(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->channel !== 'pos') {
                throw BaseApiException::conflict('Only in-person sales can be voided. Refund online orders from their payment.');
            }

            if ($locked->status === OrderStatus::Cancelled) {
                throw BaseApiException::conflict('This sale has already been voided.');
            }

            $this->inventory->release($locked);
            $locked->forceFill(['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Refunded, 'cancelled_at' => now()])->save();

            return $locked->load('items');
        });
    }

    /**
     * @param  list<array{product_id: string, variant_id?: ?string, quantity: int}>  $items
     * @return list<array{product: Product, variant: ?ProductVariant, quantity: int, unit_price: int}>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];

        foreach ($items as $i => $item) {
            $product = Product::query()->with('variants')->whereKey($item['product_id'])->first();

            if ($product === null || ! $product->isPurchasable()) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => ['This product is not available.']]);
            }

            $variant = null;

            if ($product->hasVariants()) {
                $variant = $product->variants->firstWhere('id', $item['variant_id'] ?? null);

                if ($variant === null) {
                    throw ValidationException::withMessages(["items.{$i}.variant_id" => ['Choose an option for this product.']]);
                }
            } elseif (! empty($item['variant_id'])) {
                throw ValidationException::withMessages(["items.{$i}.variant_id" => ['This product has no options.']]);
            }

            $lines[] = ['product' => $product, 'variant' => $variant, 'quantity' => (int) $item['quantity'], 'unit_price' => (int) ($variant?->price ?? $product->price)];
        }

        return $lines;
    }
}
