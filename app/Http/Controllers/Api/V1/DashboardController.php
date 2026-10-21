<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Merchant: Dashboard', weight: 20)]
class DashboardController extends Controller
{
    private const LOW_STOCK_THRESHOLD = 5;

    /** A sale counts as recovered if it happens within this many days of the cart's latest reminder. */
    private const RECOVERY_WINDOW_DAYS = 7;

    /**
     * Headline numbers for the merchant dashboard. Every query runs through the
     * tenant global scope, so a store only ever sees its own figures. Days are UTC.
     */
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]]);
        $days = $request->integer('days', 30);
        $start = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        $inRange = fn () => Order::query()->where('placed_at', '>=', $start)->where('status', '!=', OrderStatus::Cancelled->value);
        $paid = fn () => $inRange()->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value]);

        $gross = (int) $paid()->sum('total');
        $refunded = (int) Refund::query()->where('status', RefundStatus::Succeeded->value)->where('created_at', '>=', $start)->sum('amount');
        $ordersCount = $inRange()->count();

        $daily = $inRange()->selectRaw('date(placed_at) as day, count(*) as orders')->groupBy('day')->pluck('orders', 'day');
        $dailyRevenue = $paid()->selectRaw('date(placed_at) as day, sum(total) as revenue')->groupBy('day')->pluck('revenue', 'day');

        $series = collect(range(0, $days - 1))->map(function (int $i) use ($start, $daily, $dailyRevenue) {
            $day = $start->addDays($i)->toDateString();

            return ['date' => $day, 'orders' => (int) ($daily[$day] ?? 0), 'revenue' => (int) ($dailyRevenue[$day] ?? 0)];
        })->all();

        $topProducts = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $request->user()->tenant_id)
            ->where('orders.placed_at', '>=', $start)->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('order_items.name as name, sum(order_items.quantity) as quantity, sum(order_items.total) as revenue')
            ->groupBy('order_items.name')->orderByDesc('quantity')->limit(5)->get()
            ->map(fn ($row) => ['name' => $row->name, 'quantity' => (int) $row->quantity, 'revenue' => (int) $row->revenue])->all();

        return ApiResponse::success([
            'rangeDays' => $days,
            'currency' => $request->user()->tenant->store?->currency ?? 'NGN',
            'grossRevenue' => $gross,
            'refunded' => $refunded,
            'netRevenue' => $gross - $refunded,
            'orders' => $ordersCount,
            'averageOrderValue' => $ordersCount > 0 ? intdiv($gross, max($paid()->count(), 1)) : 0,
            'newCustomers' => Customer::query()->where('created_at', '>=', $start)->count(),
            'awaitingPayment' => Order::query()->where('status', OrderStatus::Pending->value)->where('payment_status', '!=', PaymentStatus::Paid->value)->count(),
            'toFulfil' => Order::query()->where('status', OrderStatus::Processing->value)->count(),
            'lowStockProducts' => Product::query()->where('status', ProductStatus::Active->value)->where('track_inventory', true)->where('stock', '<=', self::LOW_STOCK_THRESHOLD)->count(),
            'recovery' => $this->recovery($start),
            'series' => $series,
            'topProducts' => $topProducts,
            'recentOrders' => OrderResource::collection(Order::query()->with('items')->latest('placed_at')->latest('id')->limit(5)->get())->resolve(),
        ]);
    }

    /**
     * What abandoned-cart reminders brought back: orders placed from a reminded cart within a week
     * of its latest reminder. Revenue counts only orders that have been paid.
     *
     * @return array{cartsReminded: int, recoveredOrders: int, recoveredRevenue: int, byStage: array{first: array{orders: int, revenue: int}, second: array{orders: int, revenue: int}}}
     */
    private function recovery(CarbonImmutable $start): array
    {
        $paid = [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];

        $recovered = Order::query()
            ->join('carts', 'carts.id', '=', 'orders.cart_id')
            ->where('orders.placed_at', '>=', $start)->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereNotNull('carts.last_reminder_at')->whereColumn('orders.placed_at', '>=', 'carts.last_reminder_at')
            ->get(['orders.total', 'orders.payment_status', 'orders.placed_at', 'carts.last_reminder_at', 'carts.last_reminder_stage'])
            ->filter(fn ($row) => CarbonImmutable::parse($row->placed_at)->lessThanOrEqualTo(CarbonImmutable::parse($row->last_reminder_at)->addDays(self::RECOVERY_WINDOW_DAYS)));

        $byStage = ['first' => ['orders' => 0, 'revenue' => 0], 'second' => ['orders' => 0, 'revenue' => 0]];

        foreach ($recovered as $row) {
            $stage = $row->last_reminder_stage === 'second' ? 'second' : 'first';
            $isPaid = in_array($row->payment_status instanceof PaymentStatus ? $row->payment_status->value : $row->payment_status, $paid, true);
            $byStage[$stage]['orders']++;
            $byStage[$stage]['revenue'] += $isPaid ? (int) $row->total : 0;
        }

        return [
            'cartsReminded' => Cart::query()->where('last_reminder_at', '>=', $start)->count(),
            'recoveredOrders' => $recovered->count(),
            'recoveredRevenue' => $byStage['first']['revenue'] + $byStage['second']['revenue'],
            'byStage' => $byStage,
        ];
    }
}
