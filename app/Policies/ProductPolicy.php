<?php

namespace App\Policies;

use App\Enums\Permission;

class ProductPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCatalog;
    }
}
