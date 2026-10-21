<?php

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out who is in a segment. Spend and order counts come from paid, non-cancelled orders
 * (refunded ones still count: the sale happened), so "customer" always means someone who bought.
 */
class CustomerSegmentService
{
    /**
     * Customers with their paid-order figures attached as paid_orders, total_spent and last_order_at.
     *
     * @return Builder<Customer>
     */
    public function withMetrics(): Builder
    {
        $paid = Order::query()
            ->whereNotNull('customer_id')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) as paid_orders, sum(total) as total_spent, max(placed_at) as last_order_at');

        return Customer::query()
            ->leftJoinSub($paid, 'm', 'm.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw('coalesce(m.paid_orders, 0) as paid_orders, coalesce(m.total_spent, 0) as total_spent, m.last_order_at');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return Builder<Customer>
     */
    public function query(array $rules): Builder
    {
        $query = $this->withMetrics();

        if (isset($rules['min_orders'])) {
            $query->whereRaw('coalesce(m.paid_orders, 0) >= ?', [(int) $rules['min_orders']]);
        }

        if (isset($rules['max_orders'])) {
            $query->whereRaw('coalesce(m.paid_orders, 0) <= ?', [(int) $rules['max_orders']]);
        }

        if (isset($rules['min_spent'])) {
            $query->whereRaw('coalesce(m.total_spent, 0) >= ?', [(int) $rules['min_spent']]);
        }

        if (isset($rules['max_spent'])) {
            $query->whereRaw('coalesce(m.total_spent, 0) <= ?', [(int) $rules['max_spent']]);
        }

        if (isset($rules['ordered_within_days'])) {
            $query->where('m.last_order_at', '>=', now()->subDays((int) $rules['ordered_within_days']));
        }

        if (isset($rules['inactive_for_days'])) {
            // Someone who never bought is not "inactive": there is nothing to win back yet.
            $query->whereNotNull('m.last_order_at')->where('m.last_order_at', '<', now()->subDays((int) $rules['inactive_for_days']));
        }

        if (isset($rules['joined_within_days'])) {
            $query->where('customers.created_at', '>=', now()->subDays((int) $rules['joined_within_days']));
        }

        if (array_key_exists('has_account', $rules) && $rules['has_account'] !== null) {
            $rules['has_account'] ? $query->whereNotNull('customers.password') : $query->whereNull('customers.password');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public function count(array $rules): int
    {
        return $this->query($rules)->count();
    }
}
