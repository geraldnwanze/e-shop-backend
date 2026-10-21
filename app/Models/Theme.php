<?php

namespace App\Models;

use App\Enums\ThemeStatus;
use App\Traits\Audited;
use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-owned (global) storefront template; deliberately NOT tenant-scoped.
 */
#[Fillable(['name', 'slug', 'description', 'preview_image', 'status'])]
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use Audited, HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['status' => ThemeStatus::class];
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class)->withoutGlobalScopes();
    }
}
