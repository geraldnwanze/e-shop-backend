<?php

namespace App\Services\Payments;

use App\Enums\PaymentProviderType;
use App\Models\Payment;

/**
 * The only surface the rest of the application uses to talk to a payment gateway.
 * Implementations hold no tenant state; credentials are injected per call site.
 */
interface PaymentProvider
{
    public function type(): PaymentProviderType;

    public function initialize(InitializeRequest $request): InitializedPayment;

    /**
     * Ask the provider for the authoritative state of a transaction.
     */
    public function verify(string $reference): VerifiedPayment;

    /**
     * Authenticate an incoming webhook against these credentials.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public function verifyWebhookSignature(string $payload, array $headers): bool;

    /**
     * Extract the event id and payment reference from a (verified) webhook body.
     * Returns null for events this application does not act on.
     */
    public function parseWebhook(string $payload): ?WebhookNotification;

    /**
     * @param  int  $amount  minor units
     */
    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult;
}
