<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->order_number,
            'status' => $this->status,
            'paymentStatus' => $this->payment_status,
            'channel' => $this->channel,
            'cashier' => $this->when($this->channel === 'pos', fn () => ['id' => $this->cashier_id, 'name' => $this->relationLoaded('cashier') ? $this->cashier?->name : null]),
            'paymentMethod' => $this->payment_method,
            'amountTendered' => $this->amount_tendered,
            'change' => $this->amount_tendered !== null ? max(0, $this->amount_tendered - $this->total) : null,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'shippingTotal' => $this->shipping_total,
            'shippingMethod' => $this->shipping_method,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'discountCode' => $this->discount_code,
            'customer' => [
                'id' => $this->whenLoaded('customer', fn () => $this->customer?->id),
                'email' => $this->customer_email,
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
            ],
            'shippingAddress' => $this->shipping_address,
            'billingAddress' => $this->billing_address,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'productId' => $i->product_id,
                'variantId' => $i->variant_id,
                'name' => $i->name,
                'variantName' => $i->variant_name,
                'sku' => $i->sku,
                'unitPrice' => $i->unit_price,
                'quantity' => $i->quantity,
                'total' => $i->total,
            ])),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'placedAt' => $this->placed_at,
            'cancelledAt' => $this->cancelled_at,
        ];
    }
}
