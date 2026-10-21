<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'emailVerified' => $this->hasVerifiedEmail(),
            'permissions' => array_map(fn ($p) => $p->value, $this->role->permissions()),
            'tenant' => TenantResource::make($this->whenLoaded('tenant')),
            'suspendedAt' => $this->suspended_at,
            'createdAt' => $this->created_at,
        ];
    }
}
