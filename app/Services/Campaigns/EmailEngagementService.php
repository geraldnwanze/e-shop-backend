<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Counts opens and clicks for campaign recipients. Writes are single atomic updates, so two people's
 * (or one person's repeated) opens can never lose a count.
 */
class EmailEngagementService
{
    /** Mail scanners and link previewers load every link and image; their visits say nothing about the person. */
    private const AUTOMATED = '/bot|crawl|spider|preview|scan|monitor|headless|python|curl|wget|prefetch|go-http|java\//i';

    public function looksAutomated(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::AUTOMATED, $userAgent) === 1;
    }

    public function recordOpen(string $recipientId): void
    {
        $now = now();
        $stamp = $now->toDateTimeString();

        // One timestamp for every column, so "first" and "last" can never disagree by a rounding second.
        EmailCampaignRecipient::withoutTenantScope()->whereKey($recipientId)->update([
            'open_count' => DB::raw('open_count + 1'),
            'first_opened_at' => DB::raw("coalesce(first_opened_at, '{$stamp}')"),
            'last_opened_at' => $now,
        ]);
    }

    /**
     * A click proves the email was opened even if the person's mail app blocks images, so it also
     * counts as the first open when none was seen.
     */
    public function recordClick(string $recipientId): void
    {
        $now = now();
        $stamp = $now->toDateTimeString();

        EmailCampaignRecipient::withoutTenantScope()->whereKey($recipientId)->update([
            'click_count' => DB::raw('click_count + 1'),
            'first_clicked_at' => DB::raw("coalesce(first_clicked_at, '{$stamp}')"),
            'last_clicked_at' => $now,
            'open_count' => DB::raw('case when open_count = 0 then 1 else open_count end'),
            'first_opened_at' => DB::raw("coalesce(first_opened_at, '{$stamp}')"),
            'last_opened_at' => DB::raw("coalesce(last_opened_at, '{$stamp}')"),
        ]);
    }
}
