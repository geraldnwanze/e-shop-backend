<?php

namespace App\Services\Payments;

final readonly class InitializedPayment
{
    public function __construct(public string $reference, public string $authorizationUrl) {}
}
