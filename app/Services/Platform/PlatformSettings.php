<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Platform-wide settings editable by platform administrators. Only keys declared
 * in DEFAULTS exist; values are cached and invalidated on write.
 */
class PlatformSettings
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'platform.name' => 'Shop Platform',
        'platform.support_email' => null,
        'registration.enabled' => true,
        'billing.trial_days' => 14,
        'storefront.default_theme' => 'minimal',
    ];

    private const CACHE_KEY = 'platform:settings';

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = $this->cache->rememberForever(self::CACHE_KEY, fn () => PlatformSetting::query()->pluck('value', 'key')->map(fn ($v) => $v['v'] ?? null)->all());

        return array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        }

        $this->cache->forget(self::CACHE_KEY);
    }
}
