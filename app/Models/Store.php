<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Traits\Audited;
use App\Traits\BelongsToTenant;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'description', 'logo', 'favicon', 'currency', 'timezone', 'status', 'theme_id'])]
class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'review_digest_checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Theme, $this>
     */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    /**
     * @return HasOne<StoreSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    /**
     * @return HasOne<ThemeConfiguration, $this>
     */
    public function themeConfiguration(): HasOne
    {
        return $this->hasOne(ThemeConfiguration::class);
    }
}
