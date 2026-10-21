<?php

namespace App\Http\Resources;

use App\Models\EmailCampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailCampaignRecipient
 */
class CampaignRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'firstName' => $this->first_name,
            'status' => $this->status,
            'sentAt' => $this->sent_at,
            'openCount' => $this->open_count,
            'firstOpenedAt' => $this->first_opened_at,
            'lastOpenedAt' => $this->last_opened_at,
            'clickCount' => $this->click_count,
            'firstClickedAt' => $this->first_clicked_at,
            'lastClickedAt' => $this->last_clicked_at,
        ];
    }
}
