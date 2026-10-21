<?php

namespace App\Services\Payments;

final readonly class RefundResult
{
    public function __construct(public ?string $providerReference = null) {}
}
