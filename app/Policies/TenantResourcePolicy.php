<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for tenant-owned resources: requires the role permission AND that the
 * record belongs to the user's own tenant (defence in depth on top of TenantScope).
 */
abstract class TenantResourcePolicy
{
    abstract protected function permission(): Permission;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->permission());
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permission()) && $this->owns($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->permission());
    }

    public function update(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->view($user, $model);
    }

    protected function owns(User $user, Model $model): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $model->tenant_id;
    }
}
