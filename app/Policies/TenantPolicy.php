<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTenants);
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->hasPermission(Permission::ManageTenants)
            || ($user->tenant_id !== null && $user->tenant_id === $tenant->getKey());
    }

    public function suspend(User $user, Tenant $tenant): bool
    {
        return $user->hasPermission(Permission::ManageTenants);
    }

    public function activate(User $user, Tenant $tenant): bool
    {
        return $user->hasPermission(Permission::ManageTenants);
    }
}
