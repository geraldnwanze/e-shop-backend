<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * A job that has used up its attempts is logged, and emailed to the monitoring address when one is set,
 * at most once per job type per cooldown so a broken job cannot flood an inbox.
 */
class ReportFailedJob
{
    public function handle(JobFailed $event): void
    {
        $job = $event->job->resolveName();

        Log::error('Queued job failed', [
            'job' => $job,
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'exception' => $event->exception::class,
            'message' => Str::limit($event->exception->getMessage(), 300),
        ]);

        $to = config('monitoring.alert_email');

        if (! $to || ! Cache::add('monitor:failed-job-alert:'.md5($job), 1, now()->addMinutes((int) config('monitoring.alert_cooldown_minutes')))) {
            return;
        }

        try {
            Mail::raw(
                "A queued job failed after all its attempts.\n\nJob: {$job}\nQueue: {$event->job->getQueue()}\nError: ".$event->exception::class.': '.Str::limit($event->exception->getMessage(), 300)."\n\nFurther failures of this job are not emailed for ".config('monitoring.alert_cooldown_minutes').' minutes. Check the failed_jobs table and the log for the rest.',
                fn ($message) => $message->to($to)->subject('['.config('app.name').'] Queued job failed: '.class_basename($job)),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
