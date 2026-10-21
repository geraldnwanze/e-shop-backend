<?php

namespace App\Models;

use App\Enums\PaymentProviderType;
use App\Enums\PaymentStatus;
use App\Traits\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payment attempt. Status is only ever changed by PaymentConfirmationService
 * after the provider itself has confirmed the outcome.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProviderType::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function refundableAmount(): int
    {
        $reserved = (int) $this->refunds()->whereIn('status', ['pending', 'succeeded'])->sum('amount');

        return max($this->amount - $reserved, 0);
    }
}
