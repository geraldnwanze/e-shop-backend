<?php

namespace App\Services\Campaigns;

use App\Enums\ApiErrorCodeEnum;
use App\Events\CampaignSendFailed;
use App\Exceptions\BaseApiException;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignSeries;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\CampaignMessage;
use App\Support\Notifications\NotificationPreferences;
use App\Support\Notifications\StoreMailContext;
use App\Support\Notifications\UnsubscribeLink;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function __construct(private readonly CampaignAudienceService $audience, private readonly TenantContext $context) {}

    /**
     * Send now: freeze the audience and queue one email per person. Refused for stores whose owner
     * has not verified their email, and capped per campaign and per day.
     *
     * @param  array{subject: string, body: string, button_label?: ?string, button_url?: ?string}  $content
     */
    public function send(User $by, CustomerSegment $segment, array $content): EmailCampaign
    {
        $eligible = $this->assertCanSend($segment);

        $campaign = $this->newCampaign($by, $segment, $content, EmailCampaign::STATUS_SENDING);
        $campaign->recipients_count = $eligible;

        $this->launch($campaign, $segment);

        return $campaign;
    }

    /**
     * Send later. Nothing is frozen yet: who receives it is decided when the time comes, so people
     * who opt in before then are included and anyone who unsubscribes is left out.
     *
     * @param  array{subject: string, body: string, button_label?: ?string, button_url?: ?string}  $content
     */
    public function schedule(User $by, CustomerSegment $segment, array $content, CarbonInterface $at): EmailCampaign
    {
        $this->assertVerified();
        $this->assertSendTime($at);

        if ($this->audience->eligible($segment)->count() === 0) {
            throw BaseApiException::conflict('Nobody in this segment can be emailed yet. Customers appear here once they tick "email me news and offers".');
        }

        $campaign = $this->newCampaign($by, $segment, $content, EmailCampaign::STATUS_SCHEDULED);
        $campaign->scheduled_at = $at;
        $campaign->save();

        return $campaign;
    }

    /**
     * Change a campaign that has not gone out yet: its message, its segment or its time.
     *
     * @param  array{subject?: string, body?: string, button_label?: ?string, button_url?: ?string}  $content
     */
    public function reschedule(EmailCampaign $campaign, array $content, ?CustomerSegment $segment, ?CarbonInterface $at): EmailCampaign
    {
        if ($campaign->status !== EmailCampaign::STATUS_SCHEDULED) {
            throw BaseApiException::conflict('Only a campaign that has not been sent yet can be changed.');
        }

        if ($at !== null) {
            $this->assertSendTime($at);
            $campaign->scheduled_at = $at;
        }

        if ($segment !== null) {
            $campaign->customer_segment_id = $segment->getKey();
            $campaign->segment_name = $segment->name;
        }

        foreach (['subject', 'body', 'button_label', 'button_url'] as $field) {
            if (array_key_exists($field, $content)) {
                $campaign->{$field} = $content[$field];
            }
        }

        $campaign->save();

        return $campaign;
    }

    public function cancel(EmailCampaign $campaign): EmailCampaign
    {
        // One atomic step, so a campaign that is being sent at this very moment cannot be cancelled halfway.
        $claimed = EmailCampaign::query()->whereKey($campaign->getKey())->where('status', EmailCampaign::STATUS_SCHEDULED)->update(['status' => EmailCampaign::STATUS_CANCELLED]);

        if ($claimed === 0) {
            throw BaseApiException::conflict('This campaign has already been sent or cancelled.');
        }

        return $campaign->refresh();
    }

    /**
     * Send every scheduled campaign of the current store whose time has come. A campaign that can no
     * longer go out is marked failed with the reason, rather than retried forever.
     */
    public function sendDue(): int
    {
        $sent = 0;

        EmailCampaign::query()->where('status', EmailCampaign::STATUS_SCHEDULED)->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->each(function (EmailCampaign $due) use (&$sent): void {
            // Whoever updates the row first owns it: a second run, or a cancel, cannot also send it.
            $claimed = EmailCampaign::query()->whereKey($due->getKey())->where('status', EmailCampaign::STATUS_SCHEDULED)->update(['status' => EmailCampaign::STATUS_SENDING]);

            if ($claimed === 0) {
                return;
            }

            $campaign = $due->refresh();
            $segment = $campaign->customer_segment_id ? CustomerSegment::query()->find($campaign->customer_segment_id) : null;

            try {
                if ($segment === null) {
                    throw BaseApiException::conflict('The segment this campaign was meant for no longer exists.');
                }

                $campaign->recipients_count = $this->assertCanSend($segment);
                $this->launch($campaign, $segment);
                $sent++;
            } catch (BaseApiException $e) {
                $campaign->forceFill(['status' => EmailCampaign::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)])->save();
                event(new CampaignSendFailed($campaign->tenant_id, $campaign->getKey()));
            }
        });

        return $sent;
    }

    public function assertVerified(): void
    {
        if (! $this->context->requireTenant()->acceptsOrders()) {
            throw new BaseApiException(ApiErrorCodeEnum::EmailNotVerified, 'Verify your email address before sending campaigns.');
        }
    }

    private function assertSendTime(CarbonInterface $at): void
    {
        $min = (int) config('marketing.min_schedule_minutes');
        $max = (int) config('marketing.max_schedule_days');

        if ($at->lessThan(now()->addMinutes($min))) {
            throw ValidationException::withMessages(['send_at' => ["Choose a time at least {$min} minutes from now."]]);
        }

        if ($at->greaterThan(now()->addDays($max))) {
            throw ValidationException::withMessages(['send_at' => ["Campaigns can be scheduled up to {$max} days ahead."]]);
        }
    }

    /**
     * The checks every send goes through, now or later. Returns how many people it would reach.
     */
    private function assertCanSend(CustomerSegment $segment): int
    {
        $this->assertVerified();

        $eligible = $this->audience->eligible($segment)->count();

        if ($eligible === 0) {
            throw BaseApiException::conflict('Nobody in this segment can be emailed yet. Customers appear here once they tick "email me news and offers".');
        }

        $perCampaign = (int) config('marketing.max_recipients_per_campaign');

        if ($eligible > $perCampaign) {
            throw BaseApiException::conflict("A campaign can reach up to {$perCampaign} people. Narrow the segment and try again.");
        }

        $today = (int) EmailCampaign::query()->whereIn('status', [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SENT])->where('created_at', '>=', now()->startOfDay())->sum('recipients_count');
        $daily = (int) config('marketing.daily_recipient_limit');

        if ($today + $eligible > $daily) {
            throw BaseApiException::conflict('You have reached today\'s sending limit. The rest can go out tomorrow.');
        }

        return $eligible;
    }

    /**
     * @param  array{subject: string, body: string, button_label?: ?string, button_url?: ?string}  $content
     */
    private function newCampaign(User $by, CustomerSegment $segment, array $content, string $status): EmailCampaign
    {
        $campaign = new EmailCampaign([
            'segment_name' => $segment->name,
            'subject' => $content['subject'],
            'body' => $content['body'],
            'button_label' => $content['button_label'] ?? null,
            'button_url' => $content['button_url'] ?? null,
            'status' => $status,
            'recipients_count' => 0,
        ]);
        $campaign->customer_segment_id = $segment->getKey();
        $campaign->created_by = $by->getKey();

        return $campaign;
    }

    /**
     * Freeze who is in the audience right now and queue one email for each of them.
     */
    private function launch(EmailCampaign $campaign, CustomerSegment $segment): void
    {
        // Fixed when the campaign is sent, so changing the setting later never changes a campaign already out.
        $campaign->tracked = NotificationPreferences::forCurrentTenant()->enabled('campaign_tracking');

        DB::transaction(function () use ($campaign, $segment): void {
            $campaign->save();

            $this->audience->eligible($segment)->orderBy('customers.id')->each(function (Customer $c) use ($campaign): void {
                $recipient = new EmailCampaignRecipient(['email' => $c->email, 'first_name' => $c->first_name]);
                $recipient->email_campaign_id = $campaign->getKey();
                $recipient->customer_id = $c->getKey();
                $recipient->save();
            });
        });

        $tenantId = $this->context->requireTenant()->getKey();
        $campaign->recipients()->pluck('id')->each(fn (string $id) => SendCampaignEmail::dispatch($tenantId, $id)->afterCommit());
    }

    /**
     * One run of a recurring campaign: send an ordinary campaign now, or record why it could not go
     * out. After repeated failures the series pauses itself rather than failing every week.
     */
    public function runFromSeries(CampaignSeries $series): EmailCampaign
    {
        $segment = $series->customer_segment_id ? CustomerSegment::query()->find($series->customer_segment_id) : null;

        $campaign = new EmailCampaign([
            'segment_name' => $segment?->name ?? $series->segment_name,
            'subject' => $series->subject,
            'body' => $series->body,
            'button_label' => $series->button_label,
            'button_url' => $series->button_url,
            'status' => EmailCampaign::STATUS_SENDING,
            'recipients_count' => 0,
        ]);
        $campaign->customer_segment_id = $segment?->getKey();
        $campaign->campaign_series_id = $series->getKey();
        $campaign->created_by = $series->created_by;

        try {
            if ($segment === null) {
                throw BaseApiException::conflict('The segment this campaign was meant for no longer exists.');
            }

            $campaign->recipients_count = $this->assertCanSend($segment);
            $this->launch($campaign, $segment);
            $series->forceFill(['runs_count' => $series->runs_count + 1, 'consecutive_failures' => 0, 'last_run_at' => now()])->save();
        } catch (BaseApiException $e) {
            $campaign->forceFill(['status' => EmailCampaign::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 250)])->save();

            $failures = $series->consecutive_failures + 1;
            $series->forceFill(['consecutive_failures' => $failures, 'last_run_at' => now()]);

            if ($failures >= CampaignSeries::PAUSE_AFTER_FAILURES) {
                $series->forceFill(['status' => CampaignSeries::STATUS_PAUSED, 'pause_reason' => mb_substr("Paused after {$failures} runs that could not send. Last reason: {$e->getMessage()}", 0, 250)]);
            }

            $series->save();

            event(new CampaignSendFailed($campaign->tenant_id, $campaign->getKey()));
        }

        return $campaign;
    }

    /**
     * Send one recipient's email. Safe to run twice: a recipient already sent or skipped is left alone.
     */
    public function deliver(EmailCampaignRecipient $recipient): void
    {
        $campaign = $recipient->campaign;

        if ($recipient->status !== 'pending') {
            return;
        }

        // They may have unsubscribed while the campaign waited in the queue.
        if (EmailSuppression::suppresses($recipient->email)) {
            $recipient->forceFill(['status' => 'skipped'])->save();
            $this->settle($campaign);

            return;
        }

        $mail = StoreMailContext::current();
        $content = CampaignMessage::build($mail, $campaign->subject, $campaign->body, $campaign->button_label, $campaign->button_url, $recipient->first_name, UnsubscribeLink::url($this->context->requireTenant()->getKey(), $recipient->email), trackRecipientId: $campaign->tracked ? $recipient->getKey() : null);

        Notification::route('mail', $recipient->email)->notifyNow(new StoreMessageNotification('campaign', $campaign->subject, $content));

        $recipient->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $campaign->increment('sent_count');
        $this->settle($campaign);
    }

    public function markFailed(EmailCampaignRecipient $recipient, string $reason): void
    {
        if ($recipient->status !== 'pending') {
            return;
        }

        $recipient->forceFill(['status' => 'failed', 'error' => mb_substr($reason, 0, 250)])->save();
        $recipient->campaign->increment('failed_count');
        $this->settle($recipient->campaign);
    }

    /**
     * A campaign is done once nobody is still waiting.
     */
    private function settle(EmailCampaign $campaign): void
    {
        if (! $campaign->recipients()->where('status', 'pending')->exists()) {
            $campaign->forceFill(['status' => EmailCampaign::STATUS_SENT, 'completed_at' => now()])->save();
        }
    }

    /**
     * A sample sent only to the signed-in merchant.
     *
     * @param  array{subject: string, body: string, button_label?: ?string, button_url?: ?string}  $content
     */
    public function sendTest(User $to, array $content): void
    {
        $mail = StoreMailContext::current();
        $message = CampaignMessage::build($mail, $content['subject'], $content['body'], $content['button_label'] ?? null, $content['button_url'] ?? null, explode(' ', trim($to->name))[0] ?? null, null, 'This is a test sent only to you. Customers will see an unsubscribe link here.');

        Notification::route('mail', $to->email)->notify(new StoreMessageNotification('test_email', '[Test] '.$content['subject'], $message));
    }
}
