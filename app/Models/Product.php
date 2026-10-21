<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'short_description', 'sku', 'price', 'compare_at_price', 'cost_price', 'status', 'stock', 'track_inventory'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> Fields that change constantly and are not worth recording. */
    protected array $auditIgnore = ['stock', 'rating_average', 'rating_count'];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'cost_price' => 'integer',
            'stock' => 'integer',
            'track_inventory' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function hasVariants(): bool
    {
        return $this->variants->isNotEmpty();
    }

    public function isPurchasable(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    /**
     * Whether any unit can currently be bought (untracked stock is always available).
     */
    /**
     * How many units are left when that is at or below the store's notice level (0 switches the note
     * off), otherwise null. This is the only stock figure the storefront ever shows; exact levels stay private.
     */
    public static function unitsLeftNotice(bool $tracked, int $stock, int $threshold): ?int
    {
        return $tracked && $threshold > 0 && $stock > 0 && $stock <= $threshold ? $stock : null;
    }

    public function inStock(): bool
    {
        if (! $this->track_inventory) {
            return true;
        }

        return $this->hasVariants()
            ? $this->variants->contains(fn (ProductVariant $v) => $v->stock > 0)
            : $this->stock > 0;
    }
}
