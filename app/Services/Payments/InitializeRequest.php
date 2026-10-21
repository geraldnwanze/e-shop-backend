<?php

namespace App\Services\Payments;

final readonly class InitializeRequest
{
    /**
     * @param  int  $amount  minor units
     * @param  array<string, scalar>  $metadata
     */
    public function __construct(
        public string $reference,
        public int $amount,
        public string $currency,
        public string $email,
        public string $customerName,
        public string $callbackUrl,
        public array $metadata = [],
    ) {}
}
