<?php

namespace App\Policies;

use App\Enums\Permission;

class ProductReviewPolicy extends TenantResourcePolicy
{
    protected function permission(): Permission
    {
        return Permission::ManageCatalog;
    }
}
