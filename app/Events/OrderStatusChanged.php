<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
