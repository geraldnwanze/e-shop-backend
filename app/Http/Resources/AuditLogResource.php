<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'action' => $this->action,
            'tenant' => $this->tenant_id ? ['id' => $this->tenant_id, 'name' => $this->tenant_label] : null,
            'actor' => ['type' => $this->actor_type, 'id' => $this->actor_id, 'label' => $this->actor_label],
            'subject' => $this->subject_type ? ['type' => $this->subject_type, 'id' => $this->subject_id, 'label' => $this->subject_label] : null,
            'changes' => $this->changes,
            'meta' => $this->meta,
        ];
    }
}
