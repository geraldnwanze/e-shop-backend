<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'status' => $this->status,
            'amount' => $this->amount,
            'refundedAmount' => $this->refunded_amount,
            'currency' => $this->currency,
            'authorizationUrl' => $this->when($this->status === PaymentStatus::Pending, $this->authorization_url),
            'failureReason' => $this->failure_reason,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
        ];
    }
}
