<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'phone' => $this->phone,
            'hasAccount' => $this->hasAccount(),
            'acceptsMarketing' => (bool) $this->accepts_marketing,
            'ordersCount' => $this->whenCounted('orders'),
            'paidOrders' => $this->when(array_key_exists('paid_orders', $this->resource->getAttributes()), fn () => (int) $this->paid_orders),
            'totalSpent' => $this->when(array_key_exists('total_spent', $this->resource->getAttributes()), fn () => (int) $this->total_spent),
            'lastOrderAt' => $this->when(array_key_exists('last_order_at', $this->resource->getAttributes()), fn () => $this->last_order_at ? Carbon::parse($this->last_order_at) : null),
            'createdAt' => $this->created_at,
        ];
    }
}
