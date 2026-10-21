<?php

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Jobs\VerifyDomainJob;
use App\Models\Domain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('domains:verify-pending')]
#[Description('Queue DNS verification for custom domains that are pending or not yet routed')]
class VerifyPendingDomains extends Command
{
    public function handle(): int
    {
        $count = 0;

        Domain::withoutTenantScope()
            ->where('type', DomainType::Custom)
            ->whereIn('status', [DomainStatus::Pending, DomainStatus::Verified])
            ->select('id')
            ->each(function (Domain $domain) use (&$count): void {
                VerifyDomainJob::dispatch($domain->id);
                $count++;
            });

        $this->info("Queued {$count} domain verification job(s).");

        return self::SUCCESS;
    }
}
