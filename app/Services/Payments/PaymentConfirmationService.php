<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentConfirmed;
use App\Events\PaymentFailed;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place where a payment/order becomes PAID. It only ever acts on a
 * VerifiedPayment obtained from the provider's own API, and it cross-checks
 * amount and currency against what the order actually costs.
 */
class PaymentConfirmationService
{
    public function __construct(private readonly OrderService $orders) {}

    public function apply(Payment $payment, VerifiedPayment $verified): Payment
    {
        return DB::transaction(function () use ($payment, $verified): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // Idempotent: replayed webhooks / repeated verify calls change nothing.
            if (in_array($locked->status, [PaymentStatus::Paid, PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
                return $locked;
            }

            if ($verified->reference !== $locked->reference) {
                return $this->reject($locked, 'reference_mismatch', $verified);
            }

            return match ($verified->status) {
                PaymentStatus::Paid => $this->applyPaid($locked, $verified),
                PaymentStatus::Failed => $this->applyFailed($locked, $verified),
                default => $locked,
            };
        });
    }

    /**
     * 100%-discounted orders owe nothing; the backend computed that, not the client.
     */
    public function markOrderPaidWithoutPayment(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->total !== 0 || $locked->payment_status === PaymentStatus::Paid) {
                return;
            }

            $locked->payment_status = PaymentStatus::Paid;
            $locked->save();

            if ($locked->status === OrderStatus::Pending) {
                $this->orders->transition($locked, OrderStatus::Processing);
            }
        });
    }

    private function applyPaid(Payment $payment, VerifiedPayment $verified): Payment
    {
        if ($verified->amount !== $payment->amount || $verified->currency !== strtoupper($payment->currency)) {
            return $this->reject($payment, 'amount_mismatch', $verified);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'provider_reference' => $verified->providerReference,
            'failure_reason' => null,
            'meta' => array_filter($verified->meta),
        ])->save();

        $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
        $order->payment_status = PaymentStatus::Paid;
        $order->save();

        if ($order->status === OrderStatus::Pending) {
            $this->orders->transition($order, OrderStatus::Processing);
        } elseif ($order->status === OrderStatus::Cancelled) {
            Log::warning('Payment received for a cancelled order; manual refund required', ['payment_id' => $payment->id, 'order_id' => $order->id]);
        }

        Log::info('Payment confirmed', ['payment_id' => $payment->id, 'order_id' => $order->id, 'provider' => $payment->provider->value]);

        event(new PaymentConfirmed($payment->tenant_id, $order->id, $payment->id));

        return $payment->refresh();
    }

    private function applyFailed(Payment $payment, VerifiedPayment $verified): Payment
    {
        $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => $verified->meta['gateway_response'] ?? 'declined'])->save();

        $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->payment_status = PaymentStatus::Failed;
            $order->save();
        }

        Log::info('Payment failed', ['payment_id' => $payment->id, 'order_id' => $order->id]);

        event(new PaymentFailed($payment->tenant_id, $order->id, $payment->id, (string) $payment->failure_reason));

        return $payment->refresh();
    }

    /**
     * The provider says "paid" but the facts do not match our records: never
     * mark the order paid; flag it for a human.
     */
    private function reject(Payment $payment, string $reason, VerifiedPayment $verified): Payment
    {
        $payment->forceFill(['status' => PaymentStatus::Failed, 'failure_reason' => $reason])->save();

        event(new PaymentFailed($payment->tenant_id, $payment->order_id, $payment->id, $reason));

        Log::error('Payment verification mismatch', [
            'payment_id' => $payment->id, 'order_id' => $payment->order_id, 'reason' => $reason,
            'expected_amount' => $payment->amount, 'received_amount' => $verified->amount,
            'expected_currency' => $payment->currency, 'received_currency' => $verified->currency,
        ]);

        return $payment->refresh();
    }
}
