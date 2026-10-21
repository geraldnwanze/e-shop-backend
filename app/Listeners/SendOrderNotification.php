<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\Tenant;
use App\Notifications\OrderNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Customer emails for an order being placed and for each status change.
 */
class SendOrderNotification implements ShouldQueue
{
    /** What the customer is told for each status. */
    private const UPDATES = [
        'PROCESSING' => ['Your order is being prepared', 'We are getting your order ready.'],
        'SHIPPED' => ['Your order has shipped', 'Good news: your order is on its way.'],
        'DELIVERED' => ['Your order has been delivered', 'Your order has arrived. We hope you love it!'],
        'COMPLETED' => ['Your order is complete', 'Thank you for shopping with us.'],
        'CANCELLED' => ['Your order was cancelled', 'Your order has been cancelled. If you already paid, a refund will follow.'],
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function handle(OrderPlaced|OrderStatusChanged $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($event): void {
            $order = Order::query()->with('items')->find($event->orderId);

            if ($order === null) {
                return;
            }

            $preferences = NotificationPreferences::forCurrentTenant();

            if ($event instanceof OrderPlaced) {
                if (! $preferences->enabled('customer_order_confirmation')) {
                    return;
                }

                [$subject, $intro] = ['Order confirmation', 'Thanks for your order! We have received it.'];
            } else {
                // A paid order moving to PROCESSING is already covered by the payment receipt.
                $coveredByReceipt = $event->newStatus === OrderStatus::Processing->value && $order->payment_status === PaymentStatus::Paid;

                if (! $preferences->enabled('customer_order_updates') || $coveredByReceipt || ! isset(self::UPDATES[$event->newStatus])) {
                    return;
                }

                [$subject, $intro] = self::UPDATES[$event->newStatus];
            }

            $mail = StoreMailContext::current();

            Notification::route('mail', $order->customer_email)->notify(new OrderNotification($subject, $intro, [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'track_url' => $mail['trackUrl'],
                'support_email' => $mail['supportEmail'],
                'order_number' => $order->order_number,
                'name' => $order->customer_name,
                'total' => StoreMailContext::money($order->total, $order->currency),
                'items' => $order->items->map(fn ($i) => "{$i->quantity} × {$i->name}".($i->variant_name ? " ({$i->variant_name})" : ''))->all(),
            ]));
        });
    }
}
