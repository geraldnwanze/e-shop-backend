<?php

namespace App\Listeners;

use App\Events\CampaignSendFailed;
use App\Models\CampaignSeries;
use App\Models\EmailCampaign;
use App\Models\Store;
use App\Models\Tenant;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Nobody is watching a campaign that goes out at 8am on a Monday, so when one cannot be sent the
 * merchant is told: which campaign, why, and what happens next.
 */
class SendCampaignFailureAlert implements ShouldQueueAfterCommit
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(CampaignSendFailed $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->context->runAs($tenant, function () use ($event): void {
            $preferences = NotificationPreferences::forCurrentTenant();
            $campaign = EmailCampaign::query()->with('series')->find($event->campaignId);

            if ($campaign === null || ! $preferences->enabled('merchant_campaign_failure') || ! ($to = $preferences->merchantRecipient())) {
                return;
            }

            $mail = StoreMailContext::current();
            $timezone = Store::query()->value('timezone') ?: 'UTC';
            $series = $campaign->series;
            $reason = $campaign->failure_reason ?: 'An unexpected problem stopped it.';
            $subject = $campaign->subject;

            if ($series !== null) {
                $paused = $series->status === CampaignSeries::STATUS_PAUSED;
                $failures = $series->consecutive_failures;
                $intro = "A run of your recurring campaign “{$subject}” could not be sent, so nobody received it.";
                $outro = $paused
                    ? "{$failures} runs in a row have now failed, so the recurring campaign has paused itself. Fix the problem, then resume it from the Campaigns page."
                    : "That is {$failures} failed ".($failures === 1 ? 'run' : 'runs').' in a row. It will try again at its next scheduled time, and pauses itself after '.CampaignSeries::PAUSE_AFTER_FAILURES.'.';
                $heading = $paused ? 'Your recurring campaign has paused' : 'A recurring campaign run failed';
                $emailSubject = $paused ? "Recurring campaign paused: {$subject}" : "Recurring campaign could not be sent: {$subject}";
            } else {
                $due = $campaign->scheduled_at?->copy()->setTimezone($timezone);
                $intro = "Your scheduled campaign “{$subject}” was due".($due ? ' on '.$due->format('D j M \a\t H:i')." ({$timezone} time)" : '').' but could not be sent, so nobody received it.';
                $outro = 'It will not be retried automatically. Once the problem is fixed you can send the campaign again from the segment page.';
                $heading = 'A scheduled campaign could not be sent';
                $emailSubject = "Scheduled campaign could not be sent: {$subject}";
            }

            Notification::route('mail', $to)->notify(new StoreMessageNotification('campaign_failed', $emailSubject, [
                'store' => $mail['store'],
                'accent' => $mail['accent'],
                'preheader' => "{$reason}",
                'heading' => $heading,
                'intro' => $intro,
                'details' => [['Campaign', $subject], ['Segment', $campaign->segment_name], ['Why it failed', $reason]],
                'button' => ['label' => 'Open campaigns', 'url' => "{$mail['dashboardUrl']}/campaigns"],
                'outro' => $outro.' You can turn these alerts off under Settings & payments.',
            ]));
        });
    }
}
