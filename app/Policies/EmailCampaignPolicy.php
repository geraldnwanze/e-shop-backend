<?php

namespace App\Policies;

use App\Enums\Permission;

class EmailCampaignPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCustomers;
    }
}
