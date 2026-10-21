<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case MerchantOwner = 'merchant_owner';
    case MerchantAdmin = 'merchant_admin';
    case MerchantStaff = 'merchant_staff';
    case Customer = 'customer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformAdmin => [Permission::ManageTenants, Permission::ManagePlatform],
            self::MerchantOwner => [
                Permission::ManageStore, Permission::ManageDomains, Permission::ManageStaff,
                Permission::ManageCatalog, Permission::ManageOrders, Permission::ManageCustomers,
                Permission::ManageBilling, Permission::RefundPayments,
            ],
            self::MerchantAdmin => [
                Permission::ManageStore, Permission::ManageDomains, Permission::ManageStaff,
                Permission::ManageCatalog, Permission::ManageOrders, Permission::ManageCustomers,
                Permission::RefundPayments,
            ],
            self::MerchantStaff => [Permission::ManageCatalog, Permission::ManageOrders],
            self::Customer => [],
        };
    }

    public function isMerchant(): bool
    {
        return in_array($this, [self::MerchantOwner, self::MerchantAdmin, self::MerchantStaff], true);
    }

    public function isPlatform(): bool
    {
        return $this === self::PlatformAdmin;
    }
}
