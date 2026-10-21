<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\RefundRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Payments\RefundService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Orders & customers', weight: 23)]
class RefundController extends Controller
{
    /**
     * Idempotent (Idempotency-Key). Amount defaults to the full refundable remainder.
     */
    public function store(RefundRequest $request, Order $order, RefundService $refunds): JsonResponse
    {
        $this->authorize('refund', $order);

        $refund = $refunds->refund($order, $request->validated('amount'), $request->validated('reason'), $request->user()->getKey());

        return ApiResponse::created([
            'refundId' => $refund->id,
            'amount' => $refund->amount,
            'status' => $refund->status,
            'order' => OrderResource::make($order->refresh()->load(['items', 'payments'])),
        ], 'Refund issued.');
    }
}
