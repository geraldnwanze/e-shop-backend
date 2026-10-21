<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer posted a new review, or edited one so that it now needs the merchant's approval.
 * Carries ids only: queued listeners re-enter the store themselves.
 */
class ProductReviewSubmitted implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $reviewId, public readonly bool $isNew) {}
}
