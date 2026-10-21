<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A scheduled campaign, or one run of a recurring campaign, was due but could not go out. Carries
 * ids only: queued listeners re-enter the store themselves.
 */
class CampaignSendFailed implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $tenantId, public readonly string $campaignId) {}
}
