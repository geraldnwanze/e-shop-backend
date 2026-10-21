<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class OrderPolicy extends TenantResourcePolicy
{
    public function refund(User $user, Model $order): bool
    {
        return $user->hasPermission(Permission::RefundPayments) && $this->owns($user, $order);
    }

    protected function permission(): Permission
    {
        return Permission::ManageOrders;
    }
}
