<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use App\Services\Platform\PlatformSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:start-trials {--dry-run : List the stores that would get a trial without changing anything} {--notify : Email each store owner about their trial}')]
#[Description('Give existing stores that have never had a trial the standard free trial. Safe to run again: it never trials a store twice.')]
class StartTrialsForExistingStores extends Command
{
    public function handle(BillingService $billing, PlatformSettings $settings): int
    {
        if ((int) $settings->get('billing.trial_days') <= 0) {
            $this->warn('Trials are switched off (trial length is 0). Nothing was changed.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $started = 0;
        $skipped = 0;

        Tenant::query()->where('status', TenantStatus::Active->value)->orderBy('created_at')->chunkById(100, function ($tenants) use ($billing, $dryRun, &$started, &$skipped): void {
            foreach ($tenants as $tenant) {
                if (! $billing->eligibleForTrial($tenant)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("would start a trial: {$tenant->name} ({$tenant->slug})");
                    $started++;

                    continue;
                }

                $trial = $billing->startTrial($tenant);

                if ($trial === null) {
                    $this->warn("No paid plan exists to trial, so {$tenant->slug} was not changed.");

                    continue;
                }

                $this->line("trial started: {$tenant->name} ({$tenant->slug}) until {$trial->trial_ends_at->toDateString()}");
                $started++;

                if ($this->option('notify')) {
                    $billing->notifyTrialStarted($tenant, $trial);
                }
            }
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would start ' : 'Started ')."{$started} trial(s); {$skipped} store(s) not eligible.");

        return self::SUCCESS;
    }
}
