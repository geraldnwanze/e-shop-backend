<?php

namespace App\Services\Payments;

use SensitiveParameter;

final readonly class PaymentCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $secretKey,
        #[SensitiveParameter] public ?string $webhookSecret = null,
        public ?string $publicKey = null,
    ) {}

    public function __debugInfo(): array
    {
        return ['secretKey' => '***', 'webhookSecret' => '***', 'publicKey' => $this->publicKey];
    }
}
