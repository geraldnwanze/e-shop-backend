<?php

namespace App\Services\Orders;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\BaseApiException;
use App\Models\Order;
use App\Services\Inventory\InventoryService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function transition(Order $order, OrderStatus $next): Order
    {
        return DB::transaction(function () use ($order, $next): Order {
            // Re-read under lock so two concurrent transitions cannot both pass the check.
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $previous = $locked->status;

            if (! $previous->canTransitionTo($next)) {
                throw new BaseApiException(
                    ApiErrorCodeEnum::InvalidStateTransition,
                    "An order that is {$previous->value} cannot become {$next->value}.",
                );
            }

            if ($next === OrderStatus::Cancelled) {
                $this->inventory->release($locked);
                $locked->cancelled_at = now();
            }

            if ($next === OrderStatus::Delivered) {
                $locked->delivered_at = now();
            }

            $locked->status = $next;
            $locked->save();
            Audit::record('order.status_changed', $locked, ['status' => [$previous->value, $next->value]]);

            event(new OrderStatusChanged($locked->tenant_id, $locked->getKey(), $previous->value, $next->value));

            return $locked->load('items');
        });
    }
}
