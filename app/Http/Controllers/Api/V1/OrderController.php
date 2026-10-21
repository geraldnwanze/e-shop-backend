<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\InvoiceService;
use App\Services\Orders\OrderService;
use App\Support\ApiResponse;
use App\Support\InvoiceDownload;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

#[Group('Merchant: Orders & customers', weight: 23)]
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'channel' => ['nullable', Rule::in(['online', 'pos'])],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with('items')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('order_number', Like::contains($term))
                ->orWhereLike('customer_email', Like::contains($term))
                ->orWhereLike('customer_name', Like::contains($term))))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('placed_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('placed_at', '<=', $v))
            ->latest('placed_at')->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return ApiResponse::success(OrderResource::make($order->load(['items', 'customer', 'payments', 'cashier'])));
    }

    /**
     * Download the order's invoice as a PDF. Only paid (or refunded) orders have one.
     */
    public function invoice(Order $order, InvoiceService $invoices): Response
    {
        $this->authorize('view', $order);

        return InvoiceDownload::response($invoices, $order);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order, OrderService $orders): JsonResponse
    {
        $this->authorize('update', $order);

        $updated = $orders->transition($order, OrderStatus::from($request->validated('status')));

        return ApiResponse::success(OrderResource::make($updated), 'Order status updated.');
    }
}
