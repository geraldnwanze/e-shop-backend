<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The provider declined a payment, or its details did not match our records.
 */
class PaymentFailed implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $orderId, public readonly string $paymentId, public readonly string $reason) {}
}
