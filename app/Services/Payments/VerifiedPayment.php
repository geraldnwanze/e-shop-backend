<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

/**
 * What the provider itself says about a transaction (from a server-to-server call).
 */
final readonly class VerifiedPayment
{
    /**
     * @param  int  $amount  minor units
     * @param  array<string, scalar|null>  $meta  non-sensitive facts only (never tokens/card data)
     */
    public function __construct(
        public string $reference,
        public PaymentStatus $status,
        public int $amount,
        public string $currency,
        public ?string $providerReference = null,
        public array $meta = [],
    ) {}
}
