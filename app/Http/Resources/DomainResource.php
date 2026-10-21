<?php

namespace App\Http\Resources;

use App\Enums\DomainType;
use App\Models\Domain;
use App\Services\Domains\DomainVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'type' => $this->type,
            'status' => $this->status,
            'isPrimary' => $this->is_primary,
            'isVerified' => $this->is_verified,
            'verifiedAt' => $this->verified_at,
            'tenant' => $this->whenLoaded('tenant', fn () => ['id' => $this->tenant->id, 'name' => $this->tenant->name, 'slug' => $this->tenant->slug]),
            'lastCheckedAt' => $this->last_checked_at,
            'failureReason' => $this->failure_reason,
            'dnsInstructions' => $this->when(
                $this->type === DomainType::Custom && $request->routeIs('api.v1.domains.*'),
                fn () => app(DomainVerificationService::class)->instructions($this->resource),
            ),
        ];
    }
}
