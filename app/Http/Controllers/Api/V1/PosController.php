<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PosSaleRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Pos\PosService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Point of sale', weight: 24)]
class PosController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Ring up an in-person sale. The order is created paid and completed, and stock is taken straight away.
     */
    public function store(PosSaleRequest $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $order = $this->pos->sell($request->user(), $request->validated());

        return ApiResponse::created(OrderResource::make($order->load('items')), 'Sale recorded.');
    }

    /**
     * Void an in-person sale: stock returns to the shelf and the sale no longer counts as revenue.
     */
    public function void(Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        return ApiResponse::success(OrderResource::make($this->pos->void($order)), 'Sale voided.');
    }
}
