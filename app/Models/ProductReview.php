<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\ProductReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's rating and comment on a product they bought. Only published reviews are shown to
 * shoppers and counted in the product's rating.
 */
class ProductReview extends Model
{
    /** @use HasFactory<ProductReviewFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PENDING = 'pending';

    public const STATUS_HIDDEN = 'hidden';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'replied_at' => 'datetime'];
    }

    /**
     * @return HasMany<ReviewPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
