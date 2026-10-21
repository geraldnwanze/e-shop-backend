<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ThemeSeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(PlatformAdminSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoStoreSeeder::class);
            $this->call(ThemeShowcaseSeeder::class);
        }
    }
}
