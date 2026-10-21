<?php

namespace App\Services\Payments;

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentException;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackProvider implements PaymentProvider
{
    public function __construct(private readonly PaymentCredentials $credentials, private readonly string $baseUrl = 'https://api.paystack.co') {}

    public function type(): PaymentProviderType
    {
        return PaymentProviderType::Paystack;
    }

    public function initialize(InitializeRequest $request): InitializedPayment
    {
        $data = $this->unwrap($this->http()->post('/transaction/initialize', [
            'email' => $request->email,
            'amount' => $request->amount, // Paystack expects minor units
            'currency' => $request->currency,
            'reference' => $request->reference,
            'callback_url' => $request->callbackUrl,
            'metadata' => $request->metadata,
        ]));

        return new InitializedPayment($data['reference'] ?? $request->reference, $data['authorization_url'] ?? throw new PaymentException);
    }

    public function verify(string $reference): VerifiedPayment
    {
        $data = $this->unwrap($this->http()->get('/transaction/verify/'.rawurlencode($reference)));

        return new VerifiedPayment(
            reference: $data['reference'] ?? $reference,
            status: match ($data['status'] ?? null) {
                'success' => PaymentStatus::Paid,
                'failed', 'reversed' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            amount: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            meta: ['channel' => $data['channel'] ?? null, 'gateway_response' => $data['gateway_response'] ?? null],
        );
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $signature = $headers['x-paystack-signature'] ?? '';

        return $signature !== '' && hash_equals(hash_hmac('sha512', $payload, $this->credentials->secretKey), $signature);
    }

    public function parseWebhook(string $payload): ?WebhookNotification
    {
        $body = json_decode($payload, true);

        if (! is_array($body) || ($body['event'] ?? null) !== 'charge.success') {
            return null;
        }

        $reference = $body['data']['reference'] ?? null;

        return is_string($reference) ? new WebhookNotification('charge.success:'.($body['data']['id'] ?? $reference), $reference) : null;
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $data = $this->unwrap($this->http()->post('/refund', array_filter([
            'transaction' => $payment->reference,
            'amount' => $amount,
            'merchant_note' => $reason,
        ])));

        return new RefundResult(isset($data['id']) ? (string) $data['id'] : null);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->credentials->secretKey)->acceptJson()->timeout(config('payments.http_timeout', 20));
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        if ($response->failed() || $response->json('status') !== true) {
            Log::warning('Paystack request failed', ['http_status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException;
        }

        return (array) $response->json('data', []);
    }
}
