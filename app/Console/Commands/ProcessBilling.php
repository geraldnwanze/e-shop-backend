<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:process')]
#[Description('End expired trials, charge renewals that are due, drop lapsed plans to free, and send reminders')]
class ProcessBilling extends Command
{
    public function handle(BillingService $billing): int
    {
        foreach ($billing->process() as $what => $count) {
            $this->line(str_pad(str_replace('_', ' ', $what), 14).$count);
        }

        return self::SUCCESS;
    }
}
