<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('monitor:heartbeat')]
#[Description('Record that the scheduler is running and send a test job through the queue, for the health check')]
class MonitorHeartbeat extends Command
{
    public function handle(): int
    {
        Cache::put('monitor:scheduler:last', now()->timestamp, now()->addDay());
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
