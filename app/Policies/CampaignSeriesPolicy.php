<?php

namespace App\Policies;

use App\Enums\Permission;

class CampaignSeriesPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCustomers;
    }
}
