<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('platform:create-admin {email} {--name=Platform Admin} {--password= : Omit to be prompted}')]
#[Description('Create (or promote) a platform administrator. This is the only way to create one.')]
class CreatePlatformAdmin extends Command
{
    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');

        $validator = Validator::make(['email' => $email, 'password' => $password], ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']]);

        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->fill(['name' => (string) $this->option('name'), 'password' => $password]);
        $admin->tenant_id = null;
        $admin->role = UserRole::PlatformAdmin;
        $admin->email_verified_at ??= now();
        $admin->suspended_at = null;
        $admin->save();

        $this->info("Platform administrator {$email} is ready.");

        return self::SUCCESS;
    }
}
