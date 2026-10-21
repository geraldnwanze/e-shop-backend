<?php

namespace App\Console\Commands;

use App\Models\Discount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('discounts:prune-recovery-codes {--days=30 : How long after expiry an unused code is kept}')]
#[Description('Delete cart-recovery discount codes that expired without ever being used')]
class PruneRecoveryDiscounts extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        // Used codes stay: orders point at them and merchants may want to see what worked.
        $count = Discount::withoutTenantScope()
            ->where('source', Discount::SOURCE_CART_RECOVERY)
            ->where('used_count', 0)
            ->where('ends_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$count} expired unused recovery code(s).");

        return self::SUCCESS;
    }
}
