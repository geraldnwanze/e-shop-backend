<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDomains);
    }

    public function view(User $user, Domain $domain): bool
    {
        return $user->hasPermission(Permission::ManageDomains) && $this->belongsToUser($user, $domain);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageDomains);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $user->hasPermission(Permission::ManageDomains) && $this->belongsToUser($user, $domain);
    }

    private function belongsToUser(User $user, Domain $domain): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $domain->tenant_id;
    }
}
