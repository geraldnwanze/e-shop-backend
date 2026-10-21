<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A payment was verified with the provider and the order is now paid. Ids only, like OrderPlaced.
 */
class PaymentConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $orderId, public readonly string $paymentId) {}
}
