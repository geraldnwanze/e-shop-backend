<?php

namespace App\Policies;

use App\Enums\Permission;

class ShippingZonePolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageStore;
    }
}
