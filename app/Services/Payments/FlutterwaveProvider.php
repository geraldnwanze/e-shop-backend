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

/**
 * Flutterwave v3. Note: its API speaks MAJOR units (e.g. 1500.00), while this
 * application stores minor units; conversion happens only in this class.
 */
class FlutterwaveProvider implements PaymentProvider
{
    public function __construct(private readonly PaymentCredentials $credentials, private readonly string $baseUrl = 'https://api.flutterwave.com/v3') {}

    public function type(): PaymentProviderType
    {
        return PaymentProviderType::Flutterwave;
    }

    public function initialize(InitializeRequest $request): InitializedPayment
    {
        $data = $this->unwrap($this->http()->post('/payments', [
            'tx_ref' => $request->reference,
            'amount' => $this->toMajor($request->amount),
            'currency' => $request->currency,
            'redirect_url' => $request->callbackUrl,
            'customer' => ['email' => $request->email, 'name' => $request->customerName],
            'meta' => $request->metadata,
        ]));

        return new InitializedPayment($request->reference, $data['link'] ?? throw new PaymentException);
    }

    public function verify(string $reference): VerifiedPayment
    {
        $data = $this->unwrap($this->http()->get('/transactions/verify_by_reference', ['tx_ref' => $reference]));

        return new VerifiedPayment(
            reference: $data['tx_ref'] ?? $reference,
            status: match ($data['status'] ?? null) {
                'successful' => PaymentStatus::Paid,
                'failed', 'cancelled' => PaymentStatus::Failed,
                default => PaymentStatus::Pending,
            },
            amount: (int) round(((float) ($data['amount'] ?? 0)) * 100),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            meta: ['channel' => $data['payment_type'] ?? null, 'gateway_response' => $data['processor_response'] ?? null],
        );
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $secret = (string) $this->credentials->webhookSecret;
        $given = $headers['verif-hash'] ?? '';

        return $secret !== '' && $given !== '' && hash_equals($secret, $given);
    }

    public function parseWebhook(string $payload): ?WebhookNotification
    {
        $body = json_decode($payload, true);

        if (! is_array($body) || ($body['event'] ?? null) !== 'charge.completed') {
            return null;
        }

        $reference = $body['data']['tx_ref'] ?? null;

        return is_string($reference) ? new WebhookNotification('charge.completed:'.($body['data']['id'] ?? $reference), $reference) : null;
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        if ($payment->provider_reference === null) {
            throw new PaymentException('This payment cannot be refunded yet.');
        }

        $data = $this->unwrap($this->http()->post("/transactions/{$payment->provider_reference}/refund", ['amount' => $this->toMajor($amount)]));

        return new RefundResult(isset($data['id']) ? (string) $data['id'] : null);
    }

    private function toMajor(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
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
        if ($response->failed() || $response->json('status') !== 'success') {
            Log::warning('Flutterwave request failed', ['http_status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException;
        }

        return (array) $response->json('data', []);
    }
}
