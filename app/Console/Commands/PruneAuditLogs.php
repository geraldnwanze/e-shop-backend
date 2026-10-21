<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:prune')]
#[Description('Remove audit log entries older than the retention period (AUDIT_RETENTION_DAYS; 0 keeps everything)')]
class PruneAuditLogs extends Command
{
    public function handle(): int
    {
        $days = (int) config('audit.retention_days');

        if ($days <= 0) {
            $this->info('Retention is switched off; nothing removed.');

            return self::SUCCESS;
        }

        // A query delete, not model deletes: individual entries are protected, only retention may remove them.
        $removed = AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Removed {$removed} audit log entr".($removed === 1 ? 'y' : 'ies')." older than {$days} days.");

        return self::SUCCESS;
    }
}
