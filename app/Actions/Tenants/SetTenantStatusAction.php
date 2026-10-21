<?php

namespace App\Actions\Tenants;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class SetTenantStatusAction
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function suspend(Tenant $tenant): Tenant
    {
        DB::transaction(function () use ($tenant): void {
            $tenant->update(['status' => TenantStatus::Suspended, 'suspended_at' => now()]);

            PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $tenant->users()->select('id'))
                ->delete();
        });

        $this->forgetHosts($tenant);

        return $tenant->refresh();
    }

    public function activate(Tenant $tenant): Tenant
    {
        $tenant->update(['status' => TenantStatus::Active, 'suspended_at' => null]);
        $this->forgetHosts($tenant);

        return $tenant->refresh();
    }

    private function forgetHosts(Tenant $tenant): void
    {
        $tenant->domains()->pluck('domain')->each(fn (string $host) => $this->resolver->forget($host));
    }
}
