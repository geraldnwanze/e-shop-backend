<?php

namespace App\Policies;

use App\Enums\Permission;

class StorePagePolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageStore;
    }
}
