<?php

namespace App\Http\Resources;

use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Discount
 */
class DiscountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'minSubtotal' => $this->min_subtotal,
            'maxUses' => $this->max_uses,
            'usedCount' => $this->used_count,
            'startsAt' => $this->starts_at,
            'endsAt' => $this->ends_at,
            'isActive' => $this->is_active,
            'source' => $this->source,
            'isAutomatic' => $this->isAutomatic(),
        ];
    }
}
