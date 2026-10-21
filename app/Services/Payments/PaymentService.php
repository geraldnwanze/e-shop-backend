<?php

namespace App\Services\Payments;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\OrderStatus;
use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Exceptions\BaseApiException;
use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Tenancy\StorefrontUrl;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly PaymentConfirmationService $confirmation,
        private readonly TenantContext $context,
    ) {}

    /**
     * Start (or resume) a payment for an order. The amount, currency, payer and
     * callback URL all come from the database, never from the request.
     *
     * @return array{payment: ?Payment, requiresPayment: bool}
     */
    public function initialize(Order $order, ?PaymentProviderType $requested): array
    {
        if (! $this->context->requireTenant()->acceptsOrders()) {
            throw BaseApiException::storeNotAcceptingOrders();
        }

        if ($order->payment_status === PaymentStatus::Paid) {
            throw BaseApiException::conflict('This order has already been paid.');
        }

        if ($order->status !== OrderStatus::Pending) {
            throw BaseApiException::conflict('This order can no longer be paid.');
        }

        if ($order->total === 0) {
            $this->confirmation->markOrderPaidWithoutPayment($order);

            return ['payment' => null, 'requiresPayment' => false];
        }

        $type = $this->chooseProvider($requested);

        $existing = $order->payments()->where('provider', $type->value)->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '>=', now()->subMinutes(config('payments.reuse_pending_minutes')))
            ->whereNotNull('authorization_url')->first();

        if ($existing !== null) {
            return ['payment' => $existing, 'requiresPayment' => true];
        }

        $provider = $this->manager->forCurrentTenant($type) ?? throw $this->providerUnavailable();

        $payment = new Payment([
            'order_id' => $order->getKey(),
            'provider' => $type,
            'reference' => 'pay_'.Str::lower(Str::random(26)),
            'status' => PaymentStatus::Pending,
            'amount' => $order->total,
            'currency' => $order->currency,
        ]);
        $payment->save();

        try {
            $initialized = $provider->initialize(new InitializeRequest(
                reference: $payment->reference,
                amount: $payment->amount,
                currency: $payment->currency,
                email: $order->customer_email,
                customerName: $order->customer_name,
                callbackUrl: StorefrontUrl::for($this->context->requireTenant(), '/checkout/payment?'.http_build_query(['reference' => $payment->reference])),
                metadata: ['order_number' => $order->order_number, 'payment_id' => $payment->id],
            ));
        } catch (PaymentException $e) {
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'initialization_failed']);

            throw $e;
        }

        $payment->update(['authorization_url' => $initialized->authorizationUrl]);

        Log::info('Payment initialized', ['payment_id' => $payment->id, 'order_id' => $order->id, 'provider' => $type->value, 'amount' => $payment->amount]);

        return ['payment' => $payment, 'requiresPayment' => true];
    }

    /**
     * Ask the provider for the truth and apply it. Safe to call repeatedly.
     */
    public function verifyAndConfirm(Payment $payment): Payment
    {
        $provider = $this->manager->forCurrentTenant($payment->provider) ?? throw $this->providerUnavailable();

        return $this->confirmation->apply($payment, $provider->verify($payment->reference));
    }

    private function chooseProvider(?PaymentProviderType $requested): PaymentProviderType
    {
        $available = $this->manager->availableProviders();

        if ($available === []) {
            throw $this->providerUnavailable();
        }

        if ($requested !== null) {
            if (! in_array($requested, $available, true)) {
                throw ValidationException::withMessages(['provider' => ['This payment method is not available for this store.']]);
            }

            return $requested;
        }

        return $available[0];
    }

    private function providerUnavailable(): BaseApiException
    {
        return new BaseApiException(ApiErrorCodeEnum::PaymentProviderError, 'Online payment is not available for this store.', 422);
    }
}
