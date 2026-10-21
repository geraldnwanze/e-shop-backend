<?php

namespace App\Enums;

enum Permission: string
{
    case ManagePlatform = 'platform.manage';
    case ManageTenants = 'tenants.manage';
    case ManageStore = 'store.manage';
    case ManageDomains = 'domains.manage';
    case ManageStaff = 'staff.manage';
    case ManageCatalog = 'catalog.manage';
    case ManageOrders = 'orders.manage';
    case ManageCustomers = 'customers.manage';
    case ManageBilling = 'billing.manage';
    case RefundPayments = 'payments.refund';
}
