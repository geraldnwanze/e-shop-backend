<?php

namespace App\Policies;

use App\Enums\Permission;

class DiscountPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCatalog;
    }
}
