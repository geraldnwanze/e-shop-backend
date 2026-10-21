<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Carries ids only: queued listeners run without a tenant context, so they must
 * re-enter the tenant explicitly rather than rehydrate scoped models.
 */
class OrderPlaced implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $orderId) {}
}
