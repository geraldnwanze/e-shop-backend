<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\CustomerSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved group of customers defined by rules (all must match) over their paid orders and sign-up.
 * Membership is worked out live, so a segment is always current.
 */
#[Fillable(['name', 'description', 'rules'])]
class CustomerSegment extends Model
{
    /** @use HasFactory<CustomerSegmentFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const MAX_PER_STORE = 25;

    /** The rule keys a segment understands, in display order. */
    public const RULE_KEYS = ['min_orders', 'max_orders', 'min_spent', 'max_spent', 'ordered_within_days', 'inactive_for_days', 'joined_within_days', 'has_account'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
