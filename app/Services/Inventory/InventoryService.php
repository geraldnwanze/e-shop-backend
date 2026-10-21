<?php

namespace App\Services\Inventory;

use App\Enums\InventoryReason;
use App\Events\StockLow;
use App\Exceptions\BaseApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * All stock changes go through here. Decrements are single atomic
 * `UPDATE ... WHERE stock >= n` statements, so concurrent checkouts can never
 * oversell, and every change leaves an audit row in inventory_movements.
 */
class InventoryService
{
    /**
     * Take stock for an order line. Must run inside the order transaction.
     */
    public function reserve(Product $product, ?ProductVariant $variant, int $quantity, Order $order): void
    {
        if (! $product->track_inventory) {
            return;
        }

        $target = $variant ?? $product;
        $affected = $target::query()->whereKey($target->getKey())->where('stock', '>=', $quantity)->decrement('stock', $quantity);

        if ($affected === 0) {
            throw new InsufficientStockException($product->name, (int) $target::query()->whereKey($target->getKey())->value('stock'));
        }

        $this->record($product, $variant, -$quantity, InventoryReason::OrderPlaced, orderId: $order->getKey());
        $this->alertIfLow($product, $variant, $quantity);
    }

    /**
     * Fire a single alert when a sale takes stock across the store's threshold, not on every
     * sale below it.
     */
    private function alertIfLow(Product $product, ?ProductVariant $variant, int $sold): void
    {
        $threshold = NotificationPreferences::forCurrentTenant()->lowStockThreshold();
        $target = $variant ?? $product;
        $stock = (int) $target::query()->whereKey($target->getKey())->value('stock');

        if ($threshold > 0 && $stock <= $threshold && $stock + $sold > $threshold) {
            event(new StockLow($product->tenant_id, $product->getKey(), $variant?->getKey(), $stock));
        }
    }

    /**
     * Return stock for every line of a cancelled order.
     */
    public function release(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            $product = $item->product_id ? Product::withTrashed()->find($item->product_id) : null;

            if ($product === null || ! $product->track_inventory) {
                continue;
            }

            $variant = $item->variant_id ? ProductVariant::query()->find($item->variant_id) : null;
            $target = $variant ?? $product;
            $target::query()->whereKey($target->getKey())->increment('stock', $item->quantity);

            $this->record($product, $variant, $item->quantity, InventoryReason::OrderCancelled, orderId: $order->getKey());
        }
    }

    /**
     * Merchant stock adjustment (positive or negative); stock can never go below zero.
     */
    public function adjust(Product $product, ?ProductVariant $variant, int $delta, InventoryReason $reason, ?string $note = null, ?string $userId = null): InventoryMovement
    {
        return DB::transaction(function () use ($product, $variant, $delta, $reason, $note, $userId): InventoryMovement {
            $target = $variant ?? $product;

            $affected = $target::query()->whereKey($target->getKey())
                ->when($delta < 0, fn ($q) => $q->where('stock', '>=', -$delta))
                ->update(['stock' => DB::raw('stock + '.(int) $delta)]);

            if ($affected === 0) {
                throw BaseApiException::conflict('Stock cannot go below zero.');
            }

            return $this->record($product, $variant, $delta, $reason, $note, userId: $userId);
        });
    }

    public function record(Product $product, ?ProductVariant $variant, int $delta, InventoryReason $reason, ?string $note = null, ?string $orderId = null, ?string $userId = null): InventoryMovement
    {
        /** @var Model $target */
        $target = $variant ?? $product;

        return InventoryMovement::create([
            'product_id' => $product->getKey(),
            'variant_id' => $variant?->getKey(),
            'order_id' => $orderId,
            'user_id' => $userId,
            'delta' => $delta,
            'stock_after' => (int) $target::query()->whereKey($target->getKey())->value('stock'),
            'reason' => $reason,
            'note' => $note,
        ]);
    }
}
