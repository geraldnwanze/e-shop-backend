<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['code', 'type', 'value', 'min_subtotal', 'max_uses', 'starts_at', 'ends_at', 'is_active'])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> Fields that change constantly and are not worth recording. */
    protected array $auditIgnore = ['used_count'];

    /** Created by a merchant on the Discounts page. */
    public const SOURCE_MANUAL = 'manual';

    /** Issued automatically by a cart reminder, one per cart. */
    public const SOURCE_CART_RECOVERY = 'cart_recovery';

    public function isAutomatic(): bool
    {
        return $this->source === self::SOURCE_CART_RECOVERY;
    }

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'integer',
            'min_subtotal' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Amount (minor units) this discount takes off the given subtotal.
     */
    public function amountFor(int $subtotal): int
    {
        $amount = $this->type === DiscountType::Percentage
            ? intdiv($subtotal * min($this->value, 100), 100)
            : $this->value;

        return min($amount, $subtotal);
    }

    public function isRedeemableFor(int $subtotal): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture())
            && ($this->max_uses === null || $this->used_count < $this->max_uses)
            && $subtotal >= $this->min_subtotal;
    }
}
