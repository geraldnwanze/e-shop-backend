<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\ReviewPhotoFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo a customer added to a review. It is uploaded first, unattached, and joins the review when
 * the review is saved; unattached photos are cleaned up after a day.
 */
class ReviewPhoto extends Model
{
    /** @use HasFactory<ReviewPhotoFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    public const MAX_PER_REVIEW = 3;

    /** How many uploaded-but-unused photos one customer may hold at a time. */
    public const MAX_UNATTACHED = 10;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<ProductReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class, 'product_review_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
