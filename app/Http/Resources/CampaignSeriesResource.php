<?php

namespace App\Http\Resources;

use App\Models\CampaignSeries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignSeries
 */
class CampaignSeriesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'segmentId' => $this->customer_segment_id,
            'segmentName' => $this->segment_name,
            'subject' => $this->subject,
            'body' => $this->body,
            'buttonLabel' => $this->button_label,
            'buttonUrl' => $this->button_url,
            'frequency' => $this->frequency,
            'dayOfWeek' => $this->day_of_week,
            'dayOfMonth' => $this->day_of_month,
            'sendTime' => $this->send_time,
            'endsOn' => $this->ends_on?->toDateString(),
            'status' => $this->status,
            'nextRunAt' => $this->next_run_at,
            'lastRunAt' => $this->last_run_at,
            'runsCount' => $this->runs_count,
            'pauseReason' => $this->pause_reason,
            'createdAt' => $this->created_at,
        ];
    }
}
