<?php

namespace App\Listeners;

use App\Events\PaymentConfirmed;
use App\Events\PaymentFailed;
use App\Events\PaymentRefunded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Payment receipts and refund notices for customers; "new order" and "payment failed" alerts for the merchant.
 */
class SendPaymentNotifications implements ShouldQueueAfterCommit
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(PaymentConfirmed|PaymentFailed|PaymentRefunded $event): void
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
            $mail = StoreMailContext::current();

            match (true) {
                $event instanceof PaymentConfirmed => $this->confirmed($event, $order, $preferences, $mail),
                $event instanceof PaymentFailed => $this->failed($event, $order, $preferences, $mail),
                default => $this->refunded($event, $order, $preferences, $mail),
            };
        });
    }

    /**
     * @param  array<string, mixed>  $mail
     */
    private function confirmed(PaymentConfirmed $event, Order $order, NotificationPreferences $preferences, array $mail): void
    {
        $payment = Payment::query()->find($event->paymentId);

        if ($payment === null) {
            return;
        }

        $total = StoreMailContext::money($payment->amount, $payment->currency);
        $lines = $order->items->map(fn ($i) => ["{$i->quantity} × {$i->name}".($i->variant_name ? " ({$i->variant_name})" : ''), StoreMailContext::money($i->total, $order->currency)])->all();

        if ($preferences->enabled('customer_payment_receipt')) {
            Notification::route('mail', $order->customer_email)->notify(new StoreMessageNotification('payment_receipt', 'Payment received', [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "We received your payment of {$total} for order {$order->order_number}.",
                'heading' => 'Payment received',
                'intro' => "Hi {$order->customer_name}, thank you! We received your payment and are getting your order ready.",
                'details' => [['Order number', $order->order_number], ['Paid with', ucfirst($payment->provider->value)], ['Reference', $payment->reference]],
                'lines' => $lines,
                'totals' => [['Amount paid', $total]],
                'button' => ['label' => 'Track your order', 'url' => $mail['trackUrl']],
                'outro' => 'Keep this email as your receipt.',
                'reply_to' => $mail['supportEmail'],
                'footer' => $mail['supportEmail'] ? "Questions? Write to {$mail['supportEmail']}." : null,
            ]));
        }

        if ($preferences->enabled('merchant_new_order') && ($to = $preferences->merchantRecipient())) {
            Notification::route('mail', $to)->notify(new StoreMessageNotification('new_order', "New paid order {$order->order_number}", [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "{$order->customer_name} paid {$total}.",
                'heading' => 'You have a new order',
                'intro' => "{$order->customer_name} just paid for order {$order->order_number}.",
                'details' => [['Customer', $order->customer_name], ['Email', $order->customer_email], ['Order number', $order->order_number]],
                'lines' => $lines,
                'totals' => [['Paid', $total]],
                'button' => ['label' => 'View order', 'url' => "{$mail['dashboardUrl']}/orders/{$order->id}"],
                'outro' => 'You can turn these alerts off under Settings & payments in your dashboard.',
            ]));
        }
    }

    /**
     * @param  array<string, mixed>  $mail
     */
    private function failed(PaymentFailed $event, Order $order, NotificationPreferences $preferences, array $mail): void
    {
        if (! $preferences->enabled('merchant_payment_failed') || ! ($to = $preferences->merchantRecipient())) {
            return;
        }

        $total = StoreMailContext::money($order->total, $order->currency);

        Notification::route('mail', $to)->notify(new StoreMessageNotification('payment_failed', "Payment failed for order {$order->order_number}", [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => "A payment of {$total} did not go through.",
            'heading' => 'A payment did not go through',
            'intro' => "The payment for order {$order->order_number} failed. The customer can try again from their order page.",
            'details' => [['Order number', $order->order_number], ['Customer', $order->customer_email], ['Amount', $total], ['Reason', $this->readable($event->reason)]],
            'button' => ['label' => 'View order', 'url' => "{$mail['dashboardUrl']}/orders/{$order->id}"],
            'outro' => 'You can turn these alerts off under Settings & payments in your dashboard.',
        ]));
    }

    /**
     * @param  array<string, mixed>  $mail
     */
    private function refunded(PaymentRefunded $event, Order $order, NotificationPreferences $preferences, array $mail): void
    {
        $refund = Refund::query()->find($event->refundId);

        if ($refund === null || ! $preferences->enabled('customer_refund')) {
            return;
        }

        $amount = StoreMailContext::money($refund->amount, $order->currency);

        Notification::route('mail', $order->customer_email)->notify(new StoreMessageNotification('refund', 'Your refund', [
            'store' => $mail['store'],
            'accent' => $mail['accent'],
            'preheader' => "We refunded {$amount} for order {$order->order_number}.",
            'heading' => 'Your refund is on its way',
            'intro' => "Hi {$order->customer_name}, we have refunded {$amount} for order {$order->order_number}. It can take a few business days to appear on your statement.",
            'details' => array_values(array_filter([['Order number', $order->order_number], ['Refunded', $amount], $refund->reason ? ['Reason', $refund->reason] : null])),
            'button' => ['label' => 'Track your order', 'url' => $mail['trackUrl']],
            'reply_to' => $mail['supportEmail'],
            'footer' => $mail['supportEmail'] ? "Questions? Write to {$mail['supportEmail']}." : null,
        ]));
    }

    private function readable(string $reason): string
    {
        return $reason === '' ? 'Declined by the payment provider' : ucfirst(str_replace('_', ' ', $reason));
    }
}
