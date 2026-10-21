<?php

namespace App\Policies;

use App\Enums\Permission;

class CategoryPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCatalog;
    }
}
