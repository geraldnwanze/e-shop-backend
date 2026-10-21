<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\ThemeConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThemeConfiguration>
 */
class ThemeConfigurationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attrs) => Store::query()->withoutGlobalScopes()->find($attrs['store_id'])->tenant_id,
            'store_id' => Store::factory(),
            'config' => config('themes.default_configuration'),
        ];
    }
}
