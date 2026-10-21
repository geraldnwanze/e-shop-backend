<?php

namespace App\Services\Billing;

/**
 * What Paystack itself says about a platform subscription charge.
 */
final readonly class BillingCharge
{
    /**
     * @param  'paid'|'failed'|'pending'  $status
     * @param  int  $amount  minor units
     * @param  array{code: string, brand: ?string, last4: ?string}|null  $card  reusable card token, only when the card can be charged again
     */
    public function __construct(
        public string $status,
        public string $reference,
        public int $amount,
        public string $currency,
        public ?string $providerReference = null,
        public ?array $card = null,
        public ?string $message = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
