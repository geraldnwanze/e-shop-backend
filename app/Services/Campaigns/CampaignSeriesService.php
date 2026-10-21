<?php

namespace App\Services\Campaigns;

use App\Models\CampaignSeries;
use App\Models\CustomerSegment;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Recurring campaigns: when each run is due (in the store's own time zone) and starting the run.
 */
class CampaignSeriesService
{
    public function __construct(private readonly CampaignService $campaigns) {}

    /**
     * @param  array{subject: string, body: string, button_label?: ?string, button_url?: ?string}  $content
     * @param  array{frequency: string, day_of_week?: ?int, day_of_month?: ?int, send_time: string, ends_on?: ?string}  $schedule
     */
    public function create(User $by, CustomerSegment $segment, array $content, array $schedule): CampaignSeries
    {
        $this->campaigns->assertVerified();
        $this->assertSchedule($schedule);

        if (CampaignSeries::query()->where('status', '!=', CampaignSeries::STATUS_ENDED)->count() >= CampaignSeries::MAX_PER_STORE) {
            throw ValidationException::withMessages(['frequency' => ['You can have up to '.CampaignSeries::MAX_PER_STORE.' recurring campaigns. Delete one you no longer use first.']]);
        }

        $series = new CampaignSeries([
            'segment_name' => $segment->name,
            'subject' => $content['subject'],
            'body' => $content['body'],
            'button_label' => $content['button_label'] ?? null,
            'button_url' => $content['button_url'] ?? null,
            'frequency' => $schedule['frequency'],
            'day_of_week' => $schedule['frequency'] === CampaignSeries::FREQUENCY_WEEKLY ? $schedule['day_of_week'] : null,
            'day_of_month' => $schedule['frequency'] === CampaignSeries::FREQUENCY_MONTHLY ? $schedule['day_of_month'] : null,
            'send_time' => $schedule['send_time'],
            'ends_on' => $schedule['ends_on'] ?? null,
            'status' => CampaignSeries::STATUS_ACTIVE,
        ]);
        $series->customer_segment_id = $segment->getKey();
        $series->created_by = $by->getKey();
        $series->next_run_at = $this->nextRunFor($series, now()->addMinutes((int) config('marketing.min_schedule_minutes')));
        $series->save();

        return $series->refresh();
    }

    /**
     * Change the message, segment, timing or on/off state. Moving the timing or resuming always picks
     * the next run from now, so a paused series never "catches up" on the runs it missed.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $schedule
     */
    public function update(CampaignSeries $series, array $content, ?CustomerSegment $segment, array $schedule, ?string $status): CampaignSeries
    {
        foreach (['subject', 'body', 'button_label', 'button_url'] as $field) {
            if (array_key_exists($field, $content)) {
                $series->{$field} = $content[$field];
            }
        }

        if ($segment !== null) {
            $series->customer_segment_id = $segment->getKey();
            $series->segment_name = $segment->name;
        }

        $retime = $schedule !== [];

        foreach (['frequency', 'day_of_week', 'day_of_month', 'send_time', 'ends_on'] as $field) {
            if (array_key_exists($field, $schedule)) {
                $series->{$field} = $schedule[$field];
            }
        }

        if ($retime) {
            $this->assertSchedule($series->only(['frequency', 'day_of_week', 'day_of_month', 'send_time']));
            // A weekly series has a weekday, a monthly one a day of the month: keep only the one that applies.
            $series->day_of_week = $series->frequency === CampaignSeries::FREQUENCY_WEEKLY ? $series->day_of_week : null;
            $series->day_of_month = $series->frequency === CampaignSeries::FREQUENCY_MONTHLY ? $series->day_of_month : null;
        }

        $resuming = $status === CampaignSeries::STATUS_ACTIVE && $series->status !== CampaignSeries::STATUS_ACTIVE;

        if ($status !== null) {
            $series->status = $status;

            if ($status === CampaignSeries::STATUS_PAUSED) {
                $series->pause_reason = null;
            }
        }

        if ($resuming) {
            $series->consecutive_failures = 0;
            $series->pause_reason = null;
        }

        if (($retime || $resuming) && $series->status === CampaignSeries::STATUS_ACTIVE) {
            $series->next_run_at = $this->nextRunFor($series, now()->addMinutes((int) config('marketing.min_schedule_minutes')));
        }

        $series->save();

        return $series;
    }

    /**
     * Start every recurring campaign of the current store that is due. If the scheduler was down, a
     * series runs once and then carries on from the next regular time.
     */
    public function runDue(): int
    {
        $ran = 0;

        CampaignSeries::query()->where('status', CampaignSeries::STATUS_ACTIVE)->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->orderBy('next_run_at')->each(function (CampaignSeries $due) use (&$ran): void {
            $expected = $due->next_run_at;
            $next = $this->nextRunFor($due, now());

            // Whoever moves the next run on first owns this run, so overlapping scheduler ticks cannot send it twice.
            $claimed = CampaignSeries::query()->whereKey($due->getKey())->where('status', CampaignSeries::STATUS_ACTIVE)->where('next_run_at', $expected)->update(['next_run_at' => $next]);

            if ($claimed === 0) {
                return;
            }

            $series = $due->refresh();

            if ($this->hasEnded($series, $expected)) {
                $series->forceFill(['status' => CampaignSeries::STATUS_ENDED, 'next_run_at' => null])->save();

                return;
            }

            $this->campaigns->runFromSeries($series);
            $ran++;

            // The run just done was the last one if the following one would fall after the end date.
            $series->refresh();

            if ($series->status === CampaignSeries::STATUS_ACTIVE && $this->hasEnded($series, $series->next_run_at)) {
                $series->forceFill(['status' => CampaignSeries::STATUS_ENDED, 'next_run_at' => null])->save();
            }
        });

        return $ran;
    }

    /**
     * The first run of this series strictly after the given moment, as a UTC instant.
     */
    public function nextRunFor(CampaignSeries $series, CarbonInterface $after): CarbonImmutable
    {
        $local = CarbonImmutable::instance($after)->setTimezone($this->timezone());
        [$hour, $minute] = array_map('intval', explode(':', $series->send_time));
        $candidate = $local->setTime($hour, $minute, 0);

        if ($series->frequency === CampaignSeries::FREQUENCY_WEEKLY) {
            $candidate = $candidate->addDays((($series->day_of_week - $candidate->isoWeekday()) + 7) % 7);

            if ($candidate->lessThanOrEqualTo($local)) {
                $candidate = $candidate->addDays(7);
            }
        } else {
            $candidate = $candidate->day((int) $series->day_of_month);

            if ($candidate->lessThanOrEqualTo($local)) {
                $candidate = $candidate->addMonthNoOverflow()->day((int) $series->day_of_month);
            }
        }

        return $candidate->setTimezone('UTC');
    }

    private function hasEnded(CampaignSeries $series, ?CarbonInterface $runAt): bool
    {
        return $series->ends_on !== null && $runAt !== null
            && CarbonImmutable::instance($runAt)->setTimezone($this->timezone())->toDateString() > $series->ends_on->toDateString();
    }

    private function timezone(): string
    {
        return Store::query()->value('timezone') ?: 'UTC';
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function assertSchedule(array $schedule): void
    {
        $weekly = ($schedule['frequency'] ?? null) === CampaignSeries::FREQUENCY_WEEKLY;

        if ($weekly && empty($schedule['day_of_week'])) {
            throw ValidationException::withMessages(['day_of_week' => ['Choose the day of the week it should go out.']]);
        }

        if (! $weekly && empty($schedule['day_of_month'])) {
            throw ValidationException::withMessages(['day_of_month' => ['Choose the day of the month it should go out.']]);
        }
    }
}
