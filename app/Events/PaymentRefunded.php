<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class PaymentRefunded implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $orderId, public readonly string $refundId) {}
}
