<?php

namespace App\Services\Billing;

use App\Exceptions\PaymentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The platform's own Paystack account, used only to charge merchants for their plan.
 * Never mixed with the per-store credentials stores use to take customer payments.
 */
class BillingGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function configured(): bool
    {
        return (string) config('billing.paystack_secret_key') !== '';
    }

    /**
     * Start a hosted checkout and return where to send the merchant.
     *
     * @param  int  $amount  minor units
     * @param  array<string, scalar>  $metadata
     */
    public function initialize(string $reference, string $email, int $amount, string $currency, string $callbackUrl, array $metadata = []): string
    {
        $data = $this->unwrap($this->http()->post('/transaction/initialize', [
            'email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]));

        return $data['authorization_url'] ?? throw new PaymentException;
    }

    public function verify(string $reference): BillingCharge
    {
        return $this->toCharge($this->unwrap($this->http()->get('/transaction/verify/'.rawurlencode($reference))), $reference);
    }

    /**
     * Charge a saved card without the merchant being present (renewals).
     *
     * @param  int  $amount  minor units
     */
    public function chargeAuthorization(string $authorizationCode, string $email, int $amount, string $currency, string $reference): BillingCharge
    {
        return $this->toCharge($this->unwrap($this->http()->post('/transaction/charge_authorization', [
            'authorization_code' => $authorizationCode,
            'email' => $email,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
        ])), $reference);
    }

    public function validSignature(string $payload, string $signature): bool
    {
        return $this->configured() && $signature !== '' && hash_equals(hash_hmac('sha512', $payload, (string) config('billing.paystack_secret_key')), $signature);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toCharge(array $data, string $reference): BillingCharge
    {
        $authorization = is_array($data['authorization'] ?? null) ? $data['authorization'] : [];
        $reusable = ($authorization['reusable'] ?? false) === true && ! empty($authorization['authorization_code']);

        return new BillingCharge(
            status: match ($data['status'] ?? null) {
                'success' => 'paid',
                'failed', 'reversed', 'abandoned' => 'failed',
                default => 'pending',
            },
            reference: (string) ($data['reference'] ?? $reference),
            amount: (int) ($data['amount'] ?? 0),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            providerReference: isset($data['id']) ? (string) $data['id'] : null,
            card: $reusable ? ['code' => (string) $authorization['authorization_code'], 'brand' => $authorization['brand'] ?? ($authorization['card_type'] ?? null), 'last4' => $authorization['last4'] ?? null] : null,
            message: $data['gateway_response'] ?? null,
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)->withToken((string) config('billing.paystack_secret_key'))->acceptJson()->timeout(config('billing.http_timeout', 20));
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response): array
    {
        if ($response->failed() || $response->json('status') !== true) {
            Log::warning('Billing Paystack request failed', ['http_status' => $response->status(), 'message' => $response->json('message')]);

            throw new PaymentException;
        }

        return (array) $response->json('data', []);
    }
}
