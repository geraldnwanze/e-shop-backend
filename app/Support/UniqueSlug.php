<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

final class UniqueSlug
{
    /**
     * Generate a slug unique within the (already tenant-scoped) query,
     * including soft-deleted rows, which still hold the unique index.
     *
     * @param  Builder<*>  $query
     */
    public static function generate(Builder $query, string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $suffix = 1;

        while (self::exists($query, $slug, $ignoreId)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /**
     * @param  Builder<*>  $query
     */
    private static function exists(Builder $query, string $slug, ?string $ignoreId): bool
    {
        $q = (clone $query)->where('slug', $slug);

        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $q->withTrashed();
        }

        return $q->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
    }
}
