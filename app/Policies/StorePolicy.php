<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        return $this->belongsToUser($user, $store);
    }

    public function update(User $user, Store $store): bool
    {
        return $user->hasPermission(Permission::ManageStore) && $this->belongsToUser($user, $store);
    }

    private function belongsToUser(User $user, Store $store): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $store->tenant_id;
    }
}
