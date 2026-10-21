<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'plan' => PlanResource::make($this->whenLoaded('plan')),
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name, 'slug' => $this->tenant->slug]),
            'trialEndsAt' => $this->trial_ends_at,
            'currentPeriodEndsAt' => $this->current_period_ends_at,
            'cancelledAt' => $this->cancelled_at,
            'createdAt' => $this->created_at,
        ];
    }
}
