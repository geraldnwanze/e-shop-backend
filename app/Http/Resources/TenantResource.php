<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'suspendedAt' => $this->suspended_at,
            'store' => StoreResource::make($this->whenLoaded('store')),
            'domains' => DomainResource::collection($this->whenLoaded('domains')),
            'users' => UserResource::collection($this->whenLoaded('users')),
            'usersCount' => $this->whenCounted('users'),
            'subscription' => SubscriptionResource::make($this->whenLoaded('subscription')),
            'createdAt' => $this->created_at,
        ];
    }
}
