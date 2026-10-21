<?php

namespace App\Policies;

use App\Enums\Permission;

class CustomerPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCustomers;
    }
}
