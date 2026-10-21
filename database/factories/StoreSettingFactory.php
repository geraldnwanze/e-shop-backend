<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreSetting>
 */
class StoreSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attrs) => Store::query()->withoutGlobalScopes()->find($attrs['store_id'])->tenant_id,
            'store_id' => Store::factory(),
            'settings' => config('themes.default_settings'),
        ];
    }
}
