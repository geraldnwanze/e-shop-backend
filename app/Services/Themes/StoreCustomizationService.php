<?php

namespace App\Services\Themes;

use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\Theme;
use App\Models\ThemeConfiguration;
use Illuminate\Support\Facades\DB;

/**
 * Theme selection, theme configuration and store settings for one store.
 */
class StoreCustomizationService
{
    public function themeConfiguration(Store $store): ThemeConfiguration
    {
        return $store->themeConfiguration()->firstOrCreate([], [
            'config' => config('themes.default_configuration'),
            'theme_id' => $store->theme_id,
        ]);
    }

    public function settings(Store $store): StoreSetting
    {
        return $store->settings()->firstOrCreate([], ['settings' => config('themes.default_settings')]);
    }

    /**
     * Select a theme and/or merge new configuration. Groups (colors, hero, ...) are
     * merged key-by-key; lists (navigation, homepage sections) are replaced whole.
     *
     * @param  array<string, mixed>  $config
     */
    public function updateTheme(Store $store, ?Theme $theme, array $config): ThemeConfiguration
    {
        return DB::transaction(function () use ($store, $theme, $config): ThemeConfiguration {
            if ($theme !== null) {
                $store->update(['theme_id' => $theme->getKey()]);
            }

            $record = $this->themeConfiguration($store);
            $record->config = $this->merge($record->config, $config);
            $record->theme_id = $store->theme_id;
            $record->save();

            return $record;
        });
    }

    /**
     * The store's settings with platform defaults filled in, so groups added after a store was
     * created (for example email notifications) still come back complete.
     *
     * @return array<string, mixed>
     */
    public function settingsWithDefaults(Store $store): array
    {
        return array_replace_recursive(config('themes.default_settings'), $this->settings($store)->settings ?? []);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function updateSettings(Store $store, array $settings): StoreSetting
    {
        $record = $this->settings($store);
        $record->settings = $this->merge($record->settings, $settings);
        $record->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function merge(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            $current[$key] = is_array($value) && ! array_is_list($value) && isset($current[$key]) && is_array($current[$key]) && ! array_is_list($current[$key])
                ? $this->merge($current[$key], $value)
                : $value;
        }

        return $current;
    }
}
