<?php

namespace App\Policies;

use App\Enums\Permission;

class PaymentGatewayPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageBilling;
    }
}
