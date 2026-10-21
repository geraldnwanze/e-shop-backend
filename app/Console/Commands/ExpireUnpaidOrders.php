<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Orders\OrderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:expire-unpaid')]
#[Description('Cancel stale unpaid orders so their reserved stock is released')]
class ExpireUnpaidOrders extends Command
{
    public function handle(TenantContext $context, OrderService $orders): int
    {
        $hours = (int) config('payments.unpaid_order_expiry_hours');

        if ($hours <= 0) {
            $this->info('Unpaid order expiry is disabled.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        Tenant::query()->whereIn('id', Order::withoutTenantScope()->where('status', OrderStatus::Pending->value)
            ->where('payment_status', '!=', PaymentStatus::Paid->value)->where('placed_at', '<', now()->subHours($hours))
            ->select('tenant_id')->distinct())->each(function (Tenant $tenant) use ($context, $orders, $hours, &$cancelled): void {
                $context->runAs($tenant, function () use ($orders, $hours, &$cancelled): void {
                    Order::query()->where('status', OrderStatus::Pending->value)
                        ->where('payment_status', '!=', PaymentStatus::Paid->value)
                        ->where('placed_at', '<', now()->subHours($hours))
                        ->each(function (Order $order) use ($orders, &$cancelled): void {
                            $orders->transition($order, OrderStatus::Cancelled);
                            $cancelled++;
                        });
                });
            });

        $this->info("Cancelled {$cancelled} unpaid order(s).");

        return self::SUCCESS;
    }
}
