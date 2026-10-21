<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD', app()->isProduction() ? null : 'password');

        if ($password === null) {
            $this->command?->warn('SEED_ADMIN_PASSWORD not set; skipping platform admin.');

            return;
        }

        $admin = User::query()->firstOrNew(['email' => env('SEED_ADMIN_EMAIL', 'admin@'.config('tenancy.platform_domain'))]);
        $admin->fill(['name' => 'Platform Admin', 'password' => $password]);
        $admin->tenant_id = null;
        $admin->role = UserRole::PlatformAdmin;
        $admin->email_verified_at ??= now();
        $admin->save();
    }
}
