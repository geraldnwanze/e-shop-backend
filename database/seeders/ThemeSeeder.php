<?php

namespace Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    public function run(): void
    {
        $themes = [
            ['minimal', 'Minimal', 'Clean, content-first layout for any catalogue.'],
            ['fashion', 'Fashion', 'Editorial imagery and large product cards for apparel.'],
            ['electronics', 'Electronics', 'Dense grid with specs and filters for gadgets.'],
            ['beauty', 'Beauty', 'Soft palette and rich imagery for cosmetics.'],
            ['restaurant', 'Restaurant', 'Menu-style layout for food and drink.'],
            ['modern', 'Modern', 'Bold typography and contrast for lifestyle brands.'],
        ];

        foreach ($themes as [$slug, $name, $description]) {
            Theme::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'status' => 'active',
            ]);
        }
    }
}
