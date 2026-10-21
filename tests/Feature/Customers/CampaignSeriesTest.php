<?php

use App\Enums\UserRole;
use App\Models\CampaignSeries;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\EmailCampaign;
use App\Models\EmailSuppression;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->segment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 1])->create(['name' => 'Buyers']);
    // Monday 12 Oct 2026, 08:30 in Lagos (UTC+1).
    $this->travelTo(Carbon::parse('2026-10-12 07:30:00', 'UTC'));
});

function seriesShopper(string $first, bool $optedIn = true): Customer
{
    $customer = Customer::factory()->forTenant(test()->a['tenant'])->create(['first_name' => $first, 'email' => strtolower($first).'@mail.test']);
    Customer::withoutTenantScope()->whereKey($customer->id)->update(['accepts_marketing' => $optedIn]);
    Order::factory()->forTenant(test()->a['tenant'])->create(['customer_id' => $customer->id, 'customer_email' => $customer->email, 'payment_status' => 'PAID', 'status' => 'COMPLETED']);

    return $customer;
}

function seriesPayload(array $extra = []): array
{
    return $extra + [
        'segment_id' => test()->segment->id, 'subject' => 'Weekly flavours', 'body' => "Hi {first_name},\n\nSee what is new this week.",
        'frequency' => 'weekly', 'day_of_week' => 2, 'send_time' => '08:00',
    ];
}

function createSeries(array $extra = [])
{
    return test()->actingAs(test()->a['owner'], 'sanctum')->postJson('/api/v1/campaign-series', seriesPayload($extra));
}

function runRecurring(): void
{
    test()->artisan('campaigns:run-recurring')->assertSuccessful();
}

function seriesEmails(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'campaign') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function nextRun(string $id): string
{
    return CampaignSeries::withoutTenantScope()->find($id)->next_run_at->utc()->format('Y-m-d H:i');
}

// ---- creating and timing --------------------------------------------------------------------

it('creates a recurring campaign and works out the first run in the store\'s time zone', function () {
    $id = createSeries()->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.frequency', 'weekly')->assertJsonPath('data.dayOfWeek', 2)
        ->assertJsonPath('data.sendTime', '08:00')->assertJsonPath('data.runsCount', 0)->assertJsonPath('data.segmentName', 'Buyers')->json('data.id');

    // Tuesday 13 Oct 08:00 in Lagos is 07:00 UTC.
    expect(nextRun($id))->toBe('2026-10-13 07:00')->and(EmailCampaign::withoutTenantScope()->count())->toBe(0);
});

it('picks the next matching day, never one that has already passed today', function (array $schedule, string $expected) {
    $id = createSeries($schedule)->assertCreated()->json('data.id');

    expect(nextRun($id))->toBe($expected);
})->with([
    'weekly, a later weekday' => [['frequency' => 'weekly', 'day_of_week' => 5, 'send_time' => '09:00'], '2026-10-16 08:00'],
    'weekly, today but already past' => [['frequency' => 'weekly', 'day_of_week' => 1, 'send_time' => '08:00'], '2026-10-19 07:00'],
    'weekly, today and still ahead' => [['frequency' => 'weekly', 'day_of_week' => 1, 'send_time' => '10:00'], '2026-10-12 09:00'],
    'weekly, an earlier weekday' => [['frequency' => 'weekly', 'day_of_week' => 7, 'send_time' => '08:00'], '2026-10-18 07:00'],
    'monthly, later this month' => [['frequency' => 'monthly', 'day_of_week' => null, 'day_of_month' => 15, 'send_time' => '09:00'], '2026-10-15 08:00'],
    'monthly, already past this month' => [['frequency' => 'monthly', 'day_of_week' => null, 'day_of_month' => 10, 'send_time' => '09:00'], '2026-11-10 08:00'],
    'monthly, the 28th' => [['frequency' => 'monthly', 'day_of_week' => null, 'day_of_month' => 28, 'send_time' => '23:30'], '2026-10-28 22:30'],
]);

it('follows a store in another time zone, including daylight saving', function () {
    Store::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->update(['timezone' => 'America/New_York']);

    // New York is UTC-4 in October and UTC-5 after the clocks go back on 1 November.
    $october = createSeries(['frequency' => 'weekly', 'day_of_week' => 3, 'send_time' => '09:00'])->json('data.id');
    $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
    $november = createSeries(['frequency' => 'weekly', 'day_of_week' => 3, 'send_time' => '09:00'])->json('data.id');

    expect(nextRun($october))->toBe('2026-10-14 13:00')->and(nextRun($november))->toBe('2026-11-04 14:00');
});

it('validates a recurring campaign', function (array $extra, string $field) {
    createSeries($extra)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'unknown frequency' => [['frequency' => 'daily'], 'frequency'],
    'weekly without a weekday' => [['day_of_week' => null], 'day_of_week'],
    'weekday out of range' => [['day_of_week' => 8], 'day_of_week'],
    'monthly without a day' => [['frequency' => 'monthly', 'day_of_week' => null], 'day_of_month'],
    'day beyond the 28th' => [['frequency' => 'monthly', 'day_of_week' => null, 'day_of_month' => 29], 'day_of_month'],
    'time not HH:MM' => [['send_time' => '8am'], 'send_time'],
    'time out of range' => [['send_time' => '25:00'], 'send_time'],
    'end date in the past' => [['ends_on' => '2026-10-01'], 'ends_on'],
    'end date not a date' => [['ends_on' => 'someday'], 'ends_on'],
    'no subject' => [['subject' => ''], 'subject'],
    'short message' => [['body' => 'Hi'], 'body'],
    'button needs a link' => [['button_label' => 'Shop'], 'button_url'],
    'unsafe link' => [['button_label' => 'Shop', 'button_url' => 'javascript:alert(1)'], 'button_url'],
]);

it('limits how many recurring campaigns a store can have', function () {
    CampaignSeries::factory()->forTenant($this->a['tenant'])->count(CampaignSeries::MAX_PER_STORE)->create();

    createSeries()->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['frequency']]]);
});

// ---- running --------------------------------------------------------------------------------

it('sends each run as an ordinary campaign when it comes due, and plans the next', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');

    $this->travelTo(Carbon::parse('2026-10-13 06:59:00', 'UTC'));
    runRecurring();
    expect(seriesEmails())->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-10-13 07:00:30', 'UTC'));
    runRecurring();

    $campaign = EmailCampaign::withoutTenantScope()->first();
    $series = CampaignSeries::withoutTenantScope()->find($id);
    expect(seriesEmails())->toHaveCount(1)->and(seriesEmails()[0][0]->subject)->toBe('Weekly flavours')->and(seriesEmails()[0][0]->message['body'][0])->toBe('Hi Ada,')
        ->and($campaign->campaign_series_id)->toBe($id)->and($campaign->status)->toBe('sent')->and($campaign->recipients_count)->toBe(1)
        ->and($series->runs_count)->toBe(1)->and($series->last_run_at)->not->toBeNull()->and(nextRun($id))->toBe('2026-10-20 07:00');
});

it('never runs the same occurrence twice', function () {
    seriesShopper('Ada');
    createSeries();
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));

    runRecurring();
    runRecurring();

    expect(EmailCampaign::withoutTenantScope()->count())->toBe(1)->and(seriesEmails())->toHaveCount(1);
});

it('works out each run\'s audience fresh, so new opt-ins join and leavers drop out', function () {
    seriesShopper('Ada');
    $leaver = seriesShopper('Left');
    createSeries();
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();
    expect(collect(seriesEmails())->pluck(1)->sort()->values()->all())->toBe(['ada@mail.test', 'left@mail.test']);

    seriesShopper('Later');
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => $leaver->email]);
    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();

    expect(collect(array_slice(seriesEmails(), 2))->pluck(1)->sort()->values()->all())->toBe(['ada@mail.test', 'later@mail.test']);
});

it('runs once, not once per missed week, if the scheduler was down', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');

    $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
    runRecurring();

    expect(EmailCampaign::withoutTenantScope()->count())->toBe(1)->and(nextRun($id))->toBe('2026-11-10 07:00');
});

it('does not run while paused, and resuming does not catch up on missed runs', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');
    $this->patchJson("/api/v1/campaign-series/{$id}", ['status' => 'paused'])->assertOk()->assertJsonPath('data.status', 'paused');

    $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(0);

    $this->patchJson("/api/v1/campaign-series/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'active');
    runRecurring();

    // Tuesday 27 Oct at 08:00 Lagos had already passed when it was resumed, so the next Tuesday is 3 Nov.
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(0)->and(nextRun($id))->toBe('2026-11-03 07:00');
});

// ---- when a run cannot send -----------------------------------------------------------------

it('records a run that could not send, with the reason, and carries on', function () {
    $id = createSeries()->json('data.id');
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));

    runRecurring();

    $campaign = EmailCampaign::withoutTenantScope()->first();
    $series = CampaignSeries::withoutTenantScope()->find($id);
    expect($campaign->status)->toBe('failed')->and($campaign->failure_reason)->toContain('Nobody in this segment')->and($campaign->campaign_series_id)->toBe($id)
        ->and($series->consecutive_failures)->toBe(1)->and($series->runs_count)->toBe(0)->and($series->status)->toBe('active')->and(nextRun($id))->toBe('2026-10-20 07:00');
});

it('pauses itself after three failed runs in a row and says why', function () {
    $id = createSeries()->json('data.id');

    foreach (['2026-10-13', '2026-10-20', '2026-10-27'] as $day) {
        $this->travelTo(Carbon::parse("{$day} 07:01:00", 'UTC'));
        runRecurring();
    }

    $series = CampaignSeries::withoutTenantScope()->find($id);
    expect($series->status)->toBe('paused')->and($series->consecutive_failures)->toBe(3)->and($series->pause_reason)->toContain('Paused after 3 runs')->toContain('Nobody in this segment');

    $this->travelTo(Carbon::parse('2026-11-03 07:01:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(3);
});

it('starts counting failures again after a good run, and when resumed', function () {
    $id = createSeries()->json('data.id');
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();
    expect(CampaignSeries::withoutTenantScope()->find($id)->consecutive_failures)->toBe(1);

    seriesShopper('Ada');
    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();
    expect(CampaignSeries::withoutTenantScope()->find($id)->consecutive_failures)->toBe(0)->and(CampaignSeries::withoutTenantScope()->find($id)->runs_count)->toBe(1);

    CampaignSeries::withoutTenantScope()->whereKey($id)->update(['status' => 'paused', 'consecutive_failures' => 3, 'pause_reason' => 'Paused after 3 runs']);
    $this->patchJson("/api/v1/campaign-series/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.pauseReason', null);
    expect(CampaignSeries::withoutTenantScope()->find($id)->consecutive_failures)->toBe(0);
});

it('fails the run if the segment was deleted', function () {
    seriesShopper('Ada');
    createSeries();
    $this->segment->delete();
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));

    runRecurring();

    expect(EmailCampaign::withoutTenantScope()->first()->failure_reason)->toContain('no longer exists')->and(seriesEmails())->toBeEmpty();
});

it('respects the daily limit and an unverified owner', function () {
    seriesShopper('Ada');
    createSeries();
    config(['marketing.daily_recipient_limit' => 0]);
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->first()->failure_reason)->toContain('sending limit');

    config(['marketing.daily_recipient_limit' => 5000, 'tenancy.require_verified_email_to_sell' => true]);
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->latest('created_at')->first()->failure_reason)->toContain('Verify your email');
});

// ---- end date -------------------------------------------------------------------------------

it('stops after the end date, sending the last run on that day', function () {
    seriesShopper('Ada');
    $id = createSeries(['ends_on' => '2026-10-20'])->assertCreated()->assertJsonPath('data.endsOn', '2026-10-20')->json('data.id');

    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();
    expect(CampaignSeries::withoutTenantScope()->find($id)->status)->toBe('active');

    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();

    $series = CampaignSeries::withoutTenantScope()->find($id);
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(2)->and($series->status)->toBe('ended')->and($series->next_run_at)->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-27 07:01:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(2);
});

// ---- changing and deleting ------------------------------------------------------------------

it('changes the message used by later runs and moves the timing', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');

    $this->patchJson("/api/v1/campaign-series/{$id}", ['subject' => 'New subject', 'day_of_week' => 4, 'send_time' => '10:30'])
        ->assertOk()->assertJsonPath('data.subject', 'New subject')->assertJsonPath('data.dayOfWeek', 4)->assertJsonPath('data.sendTime', '10:30');

    expect(nextRun($id))->toBe('2026-10-15 09:30');
    $this->travelTo(Carbon::parse('2026-10-15 09:31:00', 'UTC'));
    runRecurring();
    expect(seriesEmails()[0][0]->subject)->toBe('New subject');
});

it('switches between weekly and monthly, keeping only the day that applies', function () {
    $id = createSeries()->json('data.id');

    $this->patchJson("/api/v1/campaign-series/{$id}", ['frequency' => 'monthly'])->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['day_of_month']]]);
    $this->patchJson("/api/v1/campaign-series/{$id}", ['frequency' => 'monthly', 'day_of_month' => 20, 'send_time' => '08:00'])->assertOk()
        ->assertJsonPath('data.frequency', 'monthly')->assertJsonPath('data.dayOfMonth', 20)->assertJsonPath('data.dayOfWeek', null);

    expect(nextRun($id))->toBe('2026-10-20 07:00');
});

it('can move to another segment, and refuses an unsupported status', function () {
    $other = CustomerSegment::factory()->forTenant($this->a['tenant'])->create(['name' => 'Everyone']);
    $id = createSeries()->json('data.id');

    $this->patchJson("/api/v1/campaign-series/{$id}", ['segment_id' => $other->id])->assertOk()->assertJsonPath('data.segmentName', 'Everyone');
    $this->patchJson("/api/v1/campaign-series/{$id}", ['status' => 'ended'])->assertUnprocessable();
});

it('deletes a recurring campaign but keeps what it already sent', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();

    $this->deleteJson("/api/v1/campaign-series/{$id}")->assertOk();

    expect(CampaignSeries::withoutTenantScope()->count())->toBe(0)->and(EmailCampaign::withoutTenantScope()->first()->campaign_series_id)->toBeNull();
    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->count())->toBe(1);
});

it('tags campaigns sent by a series so they can be told apart in the history', function () {
    seriesShopper('Ada');
    $id = createSeries()->json('data.id');
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));
    runRecurring();

    $this->getJson('/api/v1/email-campaigns')->assertOk()->assertJsonPath('data.0.seriesId', $id);
    $this->getJson('/api/v1/campaign-series')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.runsCount', 1);
});

// ---- access ---------------------------------------------------------------------------------

it('keeps recurring campaigns inside the store and for people who manage customers', function () {
    $theirSegment = CustomerSegment::factory()->forTenant($this->b['tenant'])->create();
    $theirs = CampaignSeries::factory()->forTenant($this->b['tenant'])->create(['customer_segment_id' => $theirSegment->id]);
    $mine = createSeries()->json('data.id');

    $this->getJson('/api/v1/campaign-series')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    $this->patchJson("/api/v1/campaign-series/{$theirs->id}", ['subject' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/v1/campaign-series/{$theirs->id}")->assertNotFound();
    createSeries(['segment_id' => $theirSegment->id])->assertNotFound();

    $this->travelTo(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
    CampaignSeries::withoutTenantScope()->whereKey($theirs->id)->update(['next_run_at' => now()->subMinute()]);
    runRecurring();
    expect(EmailCampaign::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->count())->toBe(1);

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/campaign-series')->assertForbidden();
    $this->postJson('/api/v1/campaign-series', seriesPayload())->assertForbidden();
    http()->getJson('/api/v1/campaign-series')->assertUnauthorized();
});

it('will not create one for an unverified store', function () {
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    config(['tenancy.require_verified_email_to_sell' => true]);

    createSeries()->assertForbidden();
});

// ---- failure alerts -------------------------------------------------------------------------

function seriesFailureAlerts(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && $row['notification']->kind === 'campaign_failed') {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

it('tells the merchant when a run of a recurring campaign fails, and counts the failures', function () {
    createSeries();
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));

    runRecurring();

    [[$alert, $to]] = seriesFailureAlerts();
    expect($to)->toBe($this->a['owner']->email)
        ->and($alert->subject)->toBe('Recurring campaign could not be sent: Weekly flavours')
        ->and($alert->message['heading'])->toBe('A recurring campaign run failed')
        ->and($alert->message['intro'])->toContain('“Weekly flavours”')
        ->and($alert->message['outro'])->toContain('That is 1 failed run in a row')->toContain('pauses itself after 3')
        ->and($alert->message['details'])->toContain(['Why it failed', 'Nobody in this segment can be emailed yet. Customers appear here once they tick "email me news and offers".']);

    $this->travelTo(Carbon::parse('2026-10-20 07:01:00', 'UTC'));
    runRecurring();
    expect(seriesFailureAlerts()[1][0]->message['outro'])->toContain('That is 2 failed runs in a row');
});

it('says clearly when the third failure paused the recurring campaign', function () {
    createSeries();

    foreach (['2026-10-13', '2026-10-20', '2026-10-27'] as $day) {
        $this->travelTo(Carbon::parse("{$day} 07:01:00", 'UTC'));
        runRecurring();
    }

    $alerts = seriesFailureAlerts();
    expect($alerts)->toHaveCount(3)
        ->and($alerts[2][0]->subject)->toBe('Recurring campaign paused: Weekly flavours')
        ->and($alerts[2][0]->message['heading'])->toBe('Your recurring campaign has paused')
        ->and($alerts[2][0]->message['outro'])->toContain('3 runs in a row have now failed')->toContain('resume it from the Campaigns page');
});

it('sends no alert for a recurring run that went out', function () {
    seriesShopper('Ada');
    createSeries();
    $this->travelTo(Carbon::parse('2026-10-13 07:01:00', 'UTC'));

    runRecurring();

    expect(seriesEmails())->toHaveCount(1)->and(seriesFailureAlerts())->toBeEmpty();
});
