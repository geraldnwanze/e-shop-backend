<?php

namespace App\Http\Resources;

use App\Models\EmailCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailCampaign
 */
class EmailCampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'segmentId' => $this->customer_segment_id,
            'seriesId' => $this->campaign_series_id,
            'segmentName' => $this->segment_name,
            'subject' => $this->subject,
            'body' => $this->body,
            'buttonLabel' => $this->button_label,
            'buttonUrl' => $this->button_url,
            'status' => $this->status,
            'tracked' => (bool) $this->tracked,
            'openedCount' => $this->when(array_key_exists('opened_count', $this->resource->getAttributes()), fn () => (int) $this->opened_count),
            'clickedCount' => $this->when(array_key_exists('clicked_count', $this->resource->getAttributes()), fn () => (int) $this->clicked_count),
            'scheduledAt' => $this->scheduled_at,
            'failureReason' => $this->failure_reason,
            'recipientsCount' => $this->recipients_count,
            'sentCount' => $this->sent_count,
            'failedCount' => $this->failed_count,
            'createdBy' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'createdAt' => $this->created_at,
            'completedAt' => $this->completed_at,
        ];
    }
}
