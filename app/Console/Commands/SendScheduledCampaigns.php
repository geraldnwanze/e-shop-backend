<?php

namespace App\Console\Commands;

use App\Models\EmailCampaign;
use App\Models\Tenant;
use App\Services\Campaigns\CampaignService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:send-scheduled')]
#[Description('Send email campaigns whose scheduled time has arrived')]
class SendScheduledCampaigns extends Command
{
    public function handle(TenantContext $context, CampaignService $campaigns): int
    {
        $sent = 0;

        Tenant::query()->whereIn('id', EmailCampaign::withoutTenantScope()->where('status', EmailCampaign::STATUS_SCHEDULED)->where('scheduled_at', '<=', now())->select('tenant_id')->distinct())
            ->each(function (Tenant $tenant) use ($context, $campaigns, &$sent): void {
                $sent += $context->runAs($tenant, fn () => $campaigns->sendDue());
            });

        $this->info("Sent {$sent} scheduled campaign(s).");

        return self::SUCCESS;
    }
}
