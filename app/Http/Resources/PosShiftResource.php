<?php

namespace App\Http\Resources;

use App\Models\PosShift;
use App\Services\Pos\PosShiftService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosShift
 */
class PosShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cashier' => ['id' => $this->user_id, 'name' => $this->whenLoaded('user', fn () => $this->user?->name)],
            'isOpen' => $this->isOpen(),
            'openedAt' => $this->opened_at,
            'closedAt' => $this->closed_at,
            'openingFloat' => $this->opening_float,
            'expectedCash' => $this->expected_cash,
            'countedCash' => $this->counted_cash,
            'variance' => $this->counted_cash !== null ? $this->counted_cash - $this->expected_cash : null,
            'notes' => $this->notes,
            'summary' => $this->when(! $request->routeIs('*pos.shifts.index'), fn () => app(PosShiftService::class)->summary($this->resource)),
        ];
    }
}
