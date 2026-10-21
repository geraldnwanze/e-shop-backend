<?php

namespace App\Services\Checkout;

use App\Models\OrderSequence;

/**
 * Human-friendly, per-tenant, per-year sequence: ORD-2026-000001.
 * Must be called inside the order-creating transaction so a rollback also
 * rolls the counter back (no gaps from failed checkouts).
 */
class OrderNumberGenerator
{
    public function next(): string
    {
        $year = (int) now()->format('Y');

        // createOrFirst() runs the insert in a savepoint, so losing the race to a concurrent
        // first-order-of-the-year cannot abort the surrounding PostgreSQL transaction.
        OrderSequence::query()->createOrFirst(['year' => $year], ['last_number' => 0]);

        $sequence = OrderSequence::query()->where('year', $year)->lockForUpdate()->firstOrFail();
        $sequence->increment('last_number');

        return sprintf('ORD-%d-%06d', $year, $sequence->last_number);
    }
}
