<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCatalog);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageCatalog);
    }

    public function update(User $user, Media $media): bool
    {
        return $user->hasPermission(Permission::ManageCatalog) && $this->owns($user, $media);
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->update($user, $media);
    }

    private function owns(User $user, Media $media): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === $media->tenant_id;
    }
}
