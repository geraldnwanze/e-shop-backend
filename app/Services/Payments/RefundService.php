<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\PaymentRefunded;
use App\Exceptions\BaseApiException;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    public function __construct(private readonly PaymentManager $manager) {}

    /**
     * The refund amount is reserved (pending row) in a short locked transaction
     * BEFORE calling the provider, so concurrent requests cannot over-refund;
     * a provider failure releases the reservation.
     */
    public function refund(Order $order, ?int $amount, ?string $reason, ?string $userId): Refund
    {
        [$payment, $refund] = DB::transaction(function () use ($order, $amount, $reason, $userId): array {
            $payment = Payment::query()->where('order_id', $order->getKey())
                ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                ->lockForUpdate()->first()
                ?? throw BaseApiException::conflict('This order has no paid payment to refund.');

            $remaining = $payment->refundableAmount();
            $amount ??= $remaining;

            if ($amount < 1 || $amount > $remaining) {
                throw BaseApiException::conflict("Refund must be between 1 and {$remaining}.");
            }

            $refund = new Refund(['payment_id' => $payment->getKey(), 'order_id' => $order->getKey(), 'user_id' => $userId, 'amount' => $amount, 'reason' => $reason, 'status' => RefundStatus::Pending]);
            $refund->save();

            return [$payment, $refund];
        });

        try {
            $provider = $this->manager->forCurrentTenant($payment->provider) ?? throw new PaymentException('Payment provider is not configured.');
            $result = $provider->refund($payment, $refund->amount, $reason);
        } catch (\Throwable $e) {
            $refund->update(['status' => RefundStatus::Failed]);

            throw $e;
        }

        DB::transaction(function () use ($refund, $payment, $order, $result): void {
            $refund->update(['status' => RefundStatus::Succeeded, 'provider_reference' => $result->providerReference]);

            $refunded = (int) $payment->refunds()->where('status', RefundStatus::Succeeded->value)->sum('amount');
            $status = $refunded >= $payment->amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;

            $payment->forceFill(['refunded_amount' => $refunded, 'status' => $status])->save();
            $order->forceFill(['payment_status' => $status])->save();
        });

        Log::info('Payment refunded', ['payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => $refund->amount]);

        event(new PaymentRefunded($payment->tenant_id, $order->id, $refund->id));

        return $refund->refresh();
    }
}
