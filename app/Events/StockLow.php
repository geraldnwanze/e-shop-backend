<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Stock for a tracked product (or variant) fell to or below the store's alert threshold.
 */
class StockLow implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly int $stock,
    ) {}
}
