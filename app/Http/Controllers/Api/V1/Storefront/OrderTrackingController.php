<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\TrackOrderRequest;
use App\Models\Order;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Storefront: Checkout & payments', weight: 12)]
class OrderTrackingController extends Controller
{
    /**
     * Order lookup by order number alone. Numbers are unique per store and the query is
     * tenant-scoped, so only the current store's order can match. Returns a deliberately
     * minimal payload (no name, email, address or phone).
     */
    public function show(TrackOrderRequest $request): JsonResponse
    {
        $order = Order::query()->with('items')
            ->where('order_number', strtoupper($request->validated('order_number')))
            ->firstOrFail();

        return ApiResponse::success([
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'total' => $order->total,
            'currency' => $order->currency,
            'placedAt' => $order->placed_at,
            'items' => $order->items->map(fn ($i) => ['name' => $i->name, 'variantName' => $i->variant_name, 'quantity' => $i->quantity])->all(),
        ]);
    }
}
