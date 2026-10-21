<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's plan assignment. Only platform administrators create or change these.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /** The saved card token never leaves the server. */
    protected $hidden = ['authorization_code'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'billing_period' => 'integer',
            'auto_renew' => 'boolean',
            'authorization_code' => 'encrypted',
            'failed_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Paid for by the merchant through self-serve billing (admin-assigned plans are not auto-managed).
     */
    public function isSelfServe(): bool
    {
        return $this->billing_period !== null;
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, SubscriptionStatus::entitled(), true);
    }
}
