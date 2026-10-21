<?php

namespace App\Console\Commands;

use App\Models\CampaignSeries;
use App\Models\Tenant;
use App\Services\Campaigns\CampaignSeriesService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:run-recurring')]
#[Description('Send the runs of recurring email campaigns that are due')]
class RunRecurringCampaigns extends Command
{
    public function handle(TenantContext $context, CampaignSeriesService $series): int
    {
        $ran = 0;

        Tenant::query()->whereIn('id', CampaignSeries::withoutTenantScope()->where('status', CampaignSeries::STATUS_ACTIVE)->where('next_run_at', '<=', now())->select('tenant_id')->distinct())
            ->each(function (Tenant $tenant) use ($context, $series, &$ran): void {
                $ran += $context->runAs($tenant, fn () => $series->runDue());
            });

        $this->info("Ran {$ran} recurring campaign(s).");

        return self::SUCCESS;
    }
}
