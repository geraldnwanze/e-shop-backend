<?php

namespace App\Models;

use App\Enums\PlanInterval;
use App\Traits\Audited;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-owned (global) plan; deliberately NOT tenant-scoped.
 */
#[Fillable(['name', 'slug', 'description', 'price', 'currency', 'interval', 'limits', 'features', 'is_active', 'position'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use Audited, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'interval' => PlanInterval::class,
            'limits' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    public function limit(string $key): ?int
    {
        $value = $this->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
