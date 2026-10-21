<?php

namespace App\Policies;

use App\Enums\Permission;

class CustomerSegmentPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCustomers;
    }
}
