<?php

use App\Enums\UserRole;
use App\Jobs\SendCampaignEmail;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailSuppression;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Support\Notifications\CampaignMessage;
use App\Support\Notifications\EmailTracking;
use App\Support\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
    $this->segment = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 1])->create(['name' => 'Buyers']);
});

/** A customer with one paid order, optionally opted in to marketing. */
function shopper(string $first, bool $optedIn = true, ?object $tenant = null, ?string $email = null): Customer
{
    $tenant ??= test()->a['tenant'];
    $customer = Customer::factory()->forTenant($tenant)->create(['first_name' => $first, 'email' => $email ?? strtolower($first).'@mail.test']);
    Customer::withoutTenantScope()->whereKey($customer->id)->update(['accepts_marketing' => $optedIn, 'marketing_opted_in_at' => $optedIn ? now() : null]);
    Order::factory()->forTenant($tenant)->create(['customer_id' => $customer->id, 'customer_email' => $customer->email, 'payment_status' => 'PAID', 'status' => 'COMPLETED', 'total' => 100000]);

    return $customer;
}

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function campaignEmails(): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass as $rows) {
            foreach ($rows as $row) {
                if ($row['notification'] instanceof StoreMessageNotification && in_array($row['notification']->kind, ['campaign', 'test_email'], true)) {
                    $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
                }
            }
        }
    }

    return $sent;
}

function campaignPayload(array $extra = []): array
{
    return $extra + ['segment_id' => test()->segment->id, 'subject' => 'Our summer offers', 'body' => "Hi {first_name},\n\nWe have new flavours.\n\nSee you soon!"];
}

function sendCampaign(array $extra = [], string $key = 'campaign-key-0001')
{
    return test()->actingAs(test()->a['owner'], 'sanctum')->postJson('/api/v1/email-campaigns', campaignPayload($extra), ['Idempotency-Key' => $key]);
}

// ---- consent capture ------------------------------------------------------------------------

it('records marketing consent only when the shopper ticks the box at checkout', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);

    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]), ['customer' => ['email' => 'yes@mail.test', 'accepts_marketing' => true]], key: 'consent-key-0001')->assertCreated();
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]), ['customer' => ['email' => 'no@mail.test']], key: 'consent-key-0002')->assertCreated();

    $yes = Customer::withoutTenantScope()->where('email', 'yes@mail.test')->first();
    expect($yes->accepts_marketing)->toBeTrue()->and($yes->marketing_opted_in_at)->not->toBeNull()
        ->and(Customer::withoutTenantScope()->where('email', 'no@mail.test')->first()->accepts_marketing)->toBeFalse();
});

it('never withdraws consent just because a later order did not tick the box', function () {
    $product = Product::factory()->forTenant($this->a['tenant'])->create(['stock' => 10]);
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]), ['customer' => ['email' => 'keep@mail.test', 'accepts_marketing' => true]], key: 'consent-key-0003')->assertCreated();
    checkout($this->a['tenant'], makeCart($this->a['tenant'], [[$product, 1]]), ['customer' => ['email' => 'keep@mail.test']], key: 'consent-key-0004')->assertCreated();

    expect(Customer::withoutTenantScope()->where('email', 'keep@mail.test')->first()->accepts_marketing)->toBeTrue();
});

it('records marketing consent at sign-up', function () {
    $register = fn (array $extra) => http()->postJson('/api/v1/storefront/auth/register', $extra + [
        'first_name' => 'Sam', 'last_name' => 'Shopper', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1',
    ], storefrontHeaders($this->a['tenant']));

    $register(['email' => 'member@mail.test', 'accepts_marketing' => true])->assertCreated();
    $register(['email' => 'quiet@mail.test'])->assertCreated();

    expect(Customer::withoutTenantScope()->where('email', 'member@mail.test')->value('accepts_marketing'))->toBeTrue()
        ->and(Customer::withoutTenantScope()->where('email', 'quiet@mail.test')->value('accepts_marketing'))->toBeFalse();
});

// ---- audience -------------------------------------------------------------------------------

it('works out who a segment email can reach and why the rest cannot be included', function () {
    shopper('Optin');
    shopper('Newsletter', optedIn: false);
    shopper('Silent', optedIn: false);
    shopper('Left');
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'left@mail.test']);
    app(TenantContext::class)->runAs($this->a['tenant'], fn () => NewsletterSubscriber::create(['email' => 'newsletter@mail.test', 'subscribed_at' => now()]));
    shopper('Other', tenant: $this->b['tenant']);

    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/email-campaigns/audience', ['segment_id' => $this->segment->id])
        ->assertOk()->assertJsonPath('data', ['inSegment' => 4, 'notOptedIn' => 1, 'unsubscribed' => 1, 'eligible' => 2]);
});

// ---- sending --------------------------------------------------------------------------------

it('emails only the eligible customers, personally, with an unsubscribe link', function () {
    shopper('Ada');
    shopper('Bola');
    shopper('Chidi', optedIn: false);
    shopper('Dayo');
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'dayo@mail.test']);

    $id = sendCampaign(['button_label' => 'Shop now', 'button_url' => 'https://icecream.example.com/products'])->assertCreated()
        ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.recipientsCount', 2)->assertJsonPath('data.sentCount', 2)->assertJsonPath('data.segmentName', 'Buyers')->json('data.id');

    $emails = campaignEmails();
    expect($emails)->toHaveCount(2)->and(collect($emails)->pluck(1)->sort()->values()->all())->toBe(['ada@mail.test', 'bola@mail.test']);

    [$ada] = collect($emails)->first(fn ($e) => $e[1] === 'ada@mail.test');
    expect($ada->subject)->toBe('Our summer offers')
        ->and($ada->message['heading'])->toBe('Our summer offers')
        ->and($ada->message['body'])->toBe(['Hi Ada,', 'We have new flavours.', 'See you soon!'])
        ->and($ada->message['button']['label'])->toBe('Shop now')->and($ada->message['button']['url'])->toContain('/api/v1/t/c/')
        ->and($ada->message['unsubscribe_url'])->toContain("/api/v1/unsubscribe/{$this->a['tenant']->id}")->toContain('ada%40mail.test')
        ->and(EmailCampaign::withoutTenantScope()->find($id)->completed_at)->not->toBeNull();
});

it('does not email anyone twice if a job runs again', function () {
    shopper('Ada');
    $id = sendCampaign()->assertCreated()->json('data.id');
    $recipient = EmailCampaignRecipient::withoutTenantScope()->where('email_campaign_id', $id)->first();

    SendCampaignEmail::dispatchSync($this->a['tenant']->id, $recipient->id);

    expect(campaignEmails())->toHaveCount(1)->and(EmailCampaign::withoutTenantScope()->find($id)->sent_count)->toBe(1);
});

it('skips someone who unsubscribed while the campaign was waiting to send', function () {
    $customer = shopper('Late');
    $campaign = EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'sending', 'recipients_count' => 1]);
    $recipient = EmailCampaignRecipient::unguarded(fn () => EmailCampaignRecipient::create(['tenant_id' => $this->a['tenant']->id, 'email_campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'email' => $customer->email, 'first_name' => 'Late']));
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => $customer->email]);

    SendCampaignEmail::dispatchSync($this->a['tenant']->id, $recipient->id);

    expect(campaignEmails())->toBeEmpty()->and($recipient->fresh()->status)->toBe('skipped')->and($campaign->fresh()->status)->toBe('sent');
});

it('marks a recipient failed after the last retry so the campaign can finish', function () {
    $customer = shopper('Broken');
    $campaign = EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'sending', 'recipients_count' => 1]);
    $recipient = EmailCampaignRecipient::unguarded(fn () => EmailCampaignRecipient::create(['tenant_id' => $this->a['tenant']->id, 'email_campaign_id' => $campaign->id, 'customer_id' => $customer->id, 'email' => $customer->email]));

    (new SendCampaignEmail($this->a['tenant']->id, $recipient->id))->failed(new RuntimeException('SMTP down'));

    expect($recipient->fresh()->status)->toBe('failed')->and($recipient->fresh()->error)->toBe('SMTP down')
        ->and($campaign->fresh()->failed_count)->toBe(1)->and($campaign->fresh()->status)->toBe('sent');
});

it('sends a campaign only once even if the request is repeated', function () {
    shopper('Ada');

    sendCampaign(key: 'campaign-same-key')->assertCreated();
    sendCampaign(key: 'campaign-same-key')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(EmailCampaign::withoutTenantScope()->count())->toBe(1)->and(campaignEmails())->toHaveCount(1);
});

it('refuses a campaign nobody can receive, an oversized one, or one past the daily limit', function () {
    sendCampaign()->assertStatus(409);

    shopper('Ada');
    shopper('Bola');
    config(['marketing.max_recipients_per_campaign' => 1]);
    sendCampaign(key: 'campaign-key-0002')->assertStatus(409);

    config(['marketing.max_recipients_per_campaign' => 100, 'marketing.daily_recipient_limit' => 3]);
    EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['recipients_count' => 2]);
    sendCampaign(key: 'campaign-key-0003')->assertStatus(409);

    expect(campaignEmails())->toBeEmpty();
});

it('will not send for a store whose owner has not verified their email', function () {
    shopper('Ada');
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    config(['tenancy.require_verified_email_to_sell' => true]);

    sendCampaign()->assertForbidden();

    expect(campaignEmails())->toBeEmpty();
});

it('validates the campaign', function (array $extra, string $field) {
    sendCampaign($extra)->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => [$field]]]);
})->with([
    'no subject' => [['subject' => ''], 'subject'],
    'too short body' => [['body' => 'Hi'], 'body'],
    'button needs a link' => [['button_label' => 'Shop'], 'button_url'],
    'button link needs a label' => [['button_url' => 'https://shop.example.com'], 'button_label'],
    'unsafe link' => [['button_label' => 'Shop', 'button_url' => 'javascript:alert(1)'], 'button_url'],
    'plain http link' => [['button_label' => 'Shop', 'button_url' => 'http://shop.example.com'], 'button_url'],
]);

it('keeps campaigns inside the store and for people who manage customers', function () {
    $theirs = CustomerSegment::factory()->forTenant($this->b['tenant'])->create();
    shopper('Ada');

    sendCampaign(['segment_id' => $theirs->id])->assertNotFound();
    $this->postJson('/api/v1/email-campaigns/audience', ['segment_id' => $theirs->id])->assertNotFound();

    $mine = EmailCampaign::factory()->forTenant($this->a['tenant'])->create();
    $other = EmailCampaign::factory()->forTenant($this->b['tenant'])->create();
    $this->getJson('/api/v1/email-campaigns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    $this->getJson("/api/v1/email-campaigns/{$mine->id}")->assertOk();
    $this->getJson("/api/v1/email-campaigns/{$other->id}")->assertNotFound();

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/email-campaigns')->assertForbidden();
    http()->getJson('/api/v1/email-campaigns')->assertUnauthorized();
});

// ---- test send ------------------------------------------------------------------------------

it('sends a test only to the signed-in merchant, without touching customers', function () {
    shopper('Ada');

    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/email-campaigns/test', ['subject' => 'Preview me', 'body' => "Hi {first_name}\n\nBody text here", 'email' => 'victim@mail.test', 'to' => 'victim@mail.test'])
        ->assertOk()->assertJsonPath('data.sentTo', $this->a['owner']->email);

    $emails = campaignEmails();
    expect($emails)->toHaveCount(1)->and($emails[0][1])->toBe($this->a['owner']->email)
        ->and($emails[0][0]->subject)->toBe('[Test] Preview me')->and($emails[0][0]->message['footer'])->toContain('test sent only to you')
        ->and(EmailCampaign::withoutTenantScope()->count())->toBe(0);
});

it('escapes campaign text in the email', function () {
    $content = CampaignMessage::build(['store' => 'Scoops', 'accent' => '#5b3df5', 'supportEmail' => null], 'Hello', "<script>alert(1)</script> {first_name}\n\nSecond", null, null, '<b>Ada</b>', null);

    $html = view('emails.branded', ['content' => $content])->render();

    expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;')->toContain('&lt;b&gt;Ada&lt;/b&gt;');
});

// ---- scheduling -----------------------------------------------------------------------------

function scheduleCampaign(?string $at = null, array $extra = [], string $key = 'schedule-key-0001')
{
    return sendCampaign(['send_at' => $at ?? now()->addHours(2)->toIso8601String()] + $extra, $key);
}

function runScheduled(): void
{
    test()->artisan('campaigns:send-scheduled')->assertSuccessful();
}

it('schedules a campaign without sending anything or freezing the audience', function () {
    shopper('Ada');

    $at = now()->addHours(2)->startOfSecond();
    scheduleCampaign($at->toIso8601String(), key: 'schedule-key-0002')->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.recipientsCount', 0)->assertJsonPath('data.segmentName', 'Buyers');

    $campaign = EmailCampaign::withoutTenantScope()->first();
    expect($campaign->scheduled_at->equalTo($at))->toBeTrue()->and(EmailCampaignRecipient::withoutTenantScope()->count())->toBe(0)->and(campaignEmails())->toBeEmpty();
});

it('sends a scheduled campaign when its time comes, and not before', function () {
    shopper('Ada');
    scheduleCampaign(now()->addHours(2)->toIso8601String())->assertCreated();

    $this->travel(119)->minutes();
    runScheduled();
    expect(campaignEmails())->toBeEmpty();

    $this->travel(2)->minutes();
    runScheduled();

    $campaign = EmailCampaign::withoutTenantScope()->first();
    expect(campaignEmails())->toHaveCount(1)->and($campaign->status)->toBe('sent')->and($campaign->recipients_count)->toBe(1)->and($campaign->sent_count)->toBe(1);
});

it('works out who receives it at send time, not when it was scheduled', function () {
    shopper('Ada');
    $leaver = shopper('Left');
    scheduleCampaign()->assertCreated();

    shopper('Later');
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => $leaver->email]);
    $this->travel(3)->hours();
    runScheduled();

    expect(collect(campaignEmails())->pluck(1)->sort()->values()->all())->toBe(['ada@mail.test', 'later@mail.test']);
});

it('sends a due campaign only once however often the scheduler runs', function () {
    shopper('Ada');
    scheduleCampaign()->assertCreated();
    $this->travel(3)->hours();

    runScheduled();
    runScheduled();

    expect(campaignEmails())->toHaveCount(1)->and(EmailCampaign::withoutTenantScope()->count())->toBe(1);
});

it('lets the merchant change a scheduled campaign\'s message and time', function () {
    shopper('Ada');
    $id = scheduleCampaign()->json('data.id');
    $newTime = now()->addDay()->startOfSecond();

    $this->patchJson("/api/v1/email-campaigns/{$id}", ['subject' => 'A better subject', 'send_at' => $newTime->toIso8601String(), 'button_label' => 'Shop', 'button_url' => 'https://shop.example.com'])
        ->assertOk()->assertJsonPath('data.subject', 'A better subject')->assertJsonPath('data.buttonLabel', 'Shop')->assertJsonPath('data.status', 'scheduled');

    expect(EmailCampaign::withoutTenantScope()->find($id)->scheduled_at->equalTo($newTime))->toBeTrue();

    $this->travel(3)->hours();
    runScheduled();
    expect(campaignEmails())->toBeEmpty();

    $this->travel(22)->hours();
    runScheduled();
    expect(campaignEmails())->toHaveCount(1)->and(campaignEmails()[0][0]->subject)->toBe('A better subject');
});

it('can move a scheduled campaign to another segment', function () {
    shopper('Ada');
    $other = CustomerSegment::factory()->forTenant($this->a['tenant'])->rules(['min_orders' => 1])->create(['name' => 'Everyone']);
    $id = scheduleCampaign()->json('data.id');

    $this->patchJson("/api/v1/email-campaigns/{$id}", ['segment_id' => $other->id])->assertOk()->assertJsonPath('data.segmentName', 'Everyone')->assertJsonPath('data.segmentId', $other->id);
});

it('lets the merchant cancel a scheduled campaign, which then never sends', function () {
    shopper('Ada');
    $id = scheduleCampaign()->json('data.id');

    $this->postJson("/api/v1/email-campaigns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->travel(3)->hours();
    runScheduled();

    expect(campaignEmails())->toBeEmpty()->and(EmailCampaign::withoutTenantScope()->first()->status)->toBe('cancelled');
    $this->postJson("/api/v1/email-campaigns/{$id}/cancel")->assertStatus(409);
});

it('refuses to change or cancel a campaign that has already gone out', function () {
    shopper('Ada');
    $id = sendCampaign()->assertCreated()->json('data.id');

    $this->postJson("/api/v1/email-campaigns/{$id}/cancel")->assertStatus(409);
    $this->patchJson("/api/v1/email-campaigns/{$id}", ['subject' => 'Too late'])->assertStatus(409);
});

it('validates the scheduled time', function (string $when) {
    shopper('Ada');

    scheduleCampaign(now()->modify($when)->toIso8601String())->assertUnprocessable()->assertJsonStructure(['meta' => ['errors' => ['send_at']]]);
})->with(['in the past' => ['-1 hour'], 'too soon' => ['+2 minutes'], 'too far ahead' => ['+120 days']]);

it('rejects a scheduled time that is not a date', function () {
    shopper('Ada');

    scheduleCampaign('next tuesday-ish')->assertUnprocessable();
});

it('will not schedule for an empty segment or an unverified store', function () {
    scheduleCampaign()->assertStatus(409);

    shopper('Ada');
    User::query()->whereKey($this->a['owner']->id)->update(['email_verified_at' => null]);
    config(['tenancy.require_verified_email_to_sell' => true]);
    scheduleCampaign(key: 'schedule-key-0003')->assertForbidden();
});

it('marks a campaign failed, with the reason, when it can no longer go out', function (callable $break, string $reason) {
    shopper('Ada');
    scheduleCampaign()->assertCreated();
    $break($this);

    $this->travel(3)->hours();
    runScheduled();

    $campaign = EmailCampaign::withoutTenantScope()->first();
    expect($campaign->status)->toBe('failed')->and($campaign->failure_reason)->toContain($reason)->and(campaignEmails())->toBeEmpty();
})->with([
    'segment deleted' => [fn ($t) => $t->segment->delete(), 'no longer exists'],
    'everyone left' => [fn ($t) => EmailSuppression::factory()->forTenant($t->a['tenant'])->create(['email' => 'ada@mail.test']), 'Nobody in this segment'],
    'over the size cap' => [fn () => config(['marketing.max_recipients_per_campaign' => 0]), 'up to 0 people'],
    'over the daily cap' => [fn () => config(['marketing.daily_recipient_limit' => 0]), 'sending limit'],
]);

it('does not count scheduled, cancelled or failed campaigns toward the daily limit', function () {
    shopper('Ada');
    config(['marketing.daily_recipient_limit' => 1]);
    EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'scheduled', 'recipients_count' => 500]);
    EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'cancelled', 'recipients_count' => 500]);
    EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'failed', 'recipients_count' => 500]);

    sendCampaign()->assertCreated();
});

it('keeps scheduled campaigns inside their store', function () {
    shopper('Ada');
    $mine = scheduleCampaign()->json('data.id');
    $theirs = EmailCampaign::factory()->forTenant($this->b['tenant'])->create(['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);

    $this->postJson("/api/v1/email-campaigns/{$theirs->id}/cancel")->assertNotFound();
    $this->patchJson("/api/v1/email-campaigns/{$theirs->id}", ['subject' => 'Hijacked'])->assertNotFound();

    $this->travel(3)->hours();
    runScheduled();
    // Their campaign was due too, but it is handled inside their own store (and has no segment, so it fails there), never in ours.
    expect(EmailCampaign::withoutTenantScope()->find($theirs->id)->status)->toBe('failed')->and(EmailCampaign::withoutTenantScope()->find($mine)->status)->toBe('sent')->and(campaignEmails())->toHaveCount(1);
});

it('needs the right person to schedule, change or cancel', function () {
    http()->postJson('/api/v1/email-campaigns', campaignPayload(['send_at' => now()->addHours(2)->toIso8601String()]), ['Idempotency-Key' => 'schedule-key-0009'])->assertUnauthorized();

    $campaign = EmailCampaign::factory()->forTenant($this->a['tenant'])->create(['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);
    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->postJson("/api/v1/email-campaigns/{$campaign->id}/cancel")->assertForbidden();
    $this->patchJson("/api/v1/email-campaigns/{$campaign->id}", ['subject' => 'No way'])->assertForbidden();
});

// ---- failure alerts -------------------------------------------------------------------------

/** @return list<array{0: StoreMessageNotification, 1: string}> */
function campaignFailureAlerts(): array
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

it('tells the merchant when a scheduled campaign could not go out, and why', function () {
    shopper('Ada');
    scheduleCampaign()->assertCreated();
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'ada@mail.test']);

    $this->travel(3)->hours();
    runScheduled();

    [[$alert, $to]] = campaignFailureAlerts();
    expect($to)->toBe($this->a['owner']->email)
        ->and($alert->subject)->toBe('Scheduled campaign could not be sent: Our summer offers')
        ->and($alert->message['heading'])->toBe('A scheduled campaign could not be sent')
        ->and($alert->message['intro'])->toContain('“Our summer offers” was due on')->toContain('Lagos')->toContain('nobody received it')
        ->and($alert->message['details'])->toContain(['Campaign', 'Our summer offers'])->toContain(['Segment', 'Buyers'])->toContain(['Why it failed', 'Nobody in this segment can be emailed yet. Customers appear here once they tick "email me news and offers".'])
        ->and($alert->message['outro'])->toContain('will not be retried')
        ->and($alert->message['button']['url'])->toEndWith('/dashboard/campaigns');
});

it('says nothing when a scheduled campaign goes out, or when it is cancelled', function () {
    shopper('Ada');
    scheduleCampaign()->assertCreated();
    $cancelled = scheduleCampaign(key: 'schedule-key-0007')->json('data.id');
    $this->postJson("/api/v1/email-campaigns/{$cancelled}/cancel")->assertOk();

    $this->travel(3)->hours();
    runScheduled();

    expect(campaignEmails())->toHaveCount(1)->and(campaignFailureAlerts())->toBeEmpty();
});

it('respects the merchant\'s choice about failure alerts and their alert address', function () {
    shopper('Ada');
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['merchant_campaign_failure' => false]])->assertOk();
    app('auth')->forgetGuards();
    scheduleCampaign(key: 'schedule-key-0008')->assertCreated();
    EmailSuppression::factory()->forTenant($this->a['tenant'])->create(['email' => 'ada@mail.test']);
    $this->travel(3)->hours();
    runScheduled();
    expect(campaignFailureAlerts())->toBeEmpty();

    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['merchant_campaign_failure' => true, 'merchant_email' => 'campaigns@icecream.test']])->assertOk();
    app('auth')->forgetGuards();
    EmailCampaign::withoutTenantScope()->update(['status' => 'scheduled', 'failure_reason' => null, 'scheduled_at' => now()->subMinute()]);
    runScheduled();

    expect(campaignFailureAlerts())->toHaveCount(1)->and(campaignFailureAlerts()[0][1])->toBe('campaigns@icecream.test');
});

it('validates the campaign failure alert setting', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['merchant_campaign_failure' => 'maybe']])->assertUnprocessable();
});

// ---- open and click tracking ----------------------------------------------------------------

const TRACKING_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

function trackedCampaign(string $key = 'track-key-0001', array $extra = []): array
{
    $id = sendCampaign($extra + ['button_label' => 'Shop now', 'button_url' => 'https://icecream.example.com/products'], $key)->assertCreated()->json('data.id');

    return [$id, EmailCampaignRecipient::withoutTenantScope()->where('email_campaign_id', $id)->orderBy('email')->get()];
}

function trackingToken(string $url): string
{
    return basename(parse_url($url, PHP_URL_PATH));
}

it('adds a hidden image and a tracked button to each recipient\'s own email', function () {
    shopper('Ada');
    shopper('Bola');
    [$id, $recipients] = trackedCampaign();

    expect(EmailCampaign::withoutTenantScope()->find($id)->tracked)->toBeTrue();
    foreach (campaignEmails() as [$notification, $to]) {
        $recipient = $recipients->firstWhere('email', $to);
        expect($notification->message['tracking_pixel'])->toContain("/api/v1/t/o/{$recipient->id}.")
            ->and($notification->message['button']['url'])->toContain("/api/v1/t/c/{$recipient->id}.")->not->toContain('icecream.example.com')
            ->and($notification->message['footer'])->toContain('We measure whether emails are opened and links are clicked')
            ->and($notification->message['unsubscribe_url'])->toContain('/api/v1/unsubscribe/')->not->toContain('/t/');
    }
});

it('sends plain links and no hidden image when the store turned tracking off', function () {
    shopper('Ada');
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['campaign_tracking' => false]])->assertOk();
    app('auth')->forgetGuards();

    [$id] = trackedCampaign();

    $message = campaignEmails()[0][0]->message;
    expect(EmailCampaign::withoutTenantScope()->find($id)->tracked)->toBeFalse()->and($message['tracking_pixel'])->toBeNull()
        ->and($message['button']['url'])->toBe('https://icecream.example.com/products')->and($message['footer'])->not->toContain('We measure');
});

it('keeps a campaign\'s tracking as it was when sent, even if the setting changes later', function () {
    shopper('Ada');
    [$id] = trackedCampaign();

    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['campaign_tracking' => false]])->assertOk();

    expect(EmailCampaign::withoutTenantScope()->find($id)->tracked)->toBeTrue();
});

it('counts an open each time the hidden image loads, and always answers with the picture', function () {
    shopper('Ada');
    [, $recipients] = trackedCampaign();
    $url = trackingToken(campaignEmails()[0][0]->message['tracking_pixel']);

    $first = http()->get("/api/v1/t/o/{$url}", ['User-Agent' => TRACKING_BROWSER]);
    $first->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect(substr($first->getContent(), 0, 3))->toBe('GIF');

    http()->get("/api/v1/t/o/{$url}", ['User-Agent' => TRACKING_BROWSER])->assertOk();

    $row = $recipients[0]->fresh();
    expect($row->open_count)->toBe(2)->and($row->first_opened_at)->not->toBeNull()->and($row->last_opened_at)->not->toBeNull()->and($row->click_count)->toBe(0);
});

it('ignores forged links for opens and clicks without revealing anything', function () {
    shopper('Ada');
    [, $recipients] = trackedCampaign();
    $id = $recipients[0]->id;

    foreach (["{$id}.deadbeefdeadbeefdeadbeefdeadbeef", "{$id}", 'nonsense', "{$id}.", (string) Str::uuid().'.00000000000000000000000000000000'] as $token) {
        http()->get("/api/v1/t/o/{$token}", ['User-Agent' => TRACKING_BROWSER])->assertOk()->assertHeader('Content-Type', 'image/gif');
        http()->get("/api/v1/t/c/{$token}", ['User-Agent' => TRACKING_BROWSER])->assertNotFound();
    }

    expect($recipients[0]->fresh()->open_count)->toBe(0)->and($recipients[0]->fresh()->click_count)->toBe(0);
});

it('does not count visits from mail scanners and link previewers', function (string $agent) {
    shopper('Ada');
    [, $recipients] = trackedCampaign();
    $message = campaignEmails()[0][0]->message;

    http()->get('/api/v1/t/o/'.trackingToken($message['tracking_pixel']), ['User-Agent' => $agent])->assertOk();
    $click = http()->get('/api/v1/t/c/'.trackingToken($message['button']['url']), ['User-Agent' => $agent]);

    // The person still lands where they were going; it just is not counted as them.
    $click->assertRedirect('https://icecream.example.com/products');
    expect($recipients[0]->fresh()->open_count)->toBe(0)->and($recipients[0]->fresh()->click_count)->toBe(0);
})->with([
    'a link scanner' => ['Mozilla/5.0 (compatible; LinkScanBot/2.1)'],
    'a crawler' => ['Googlebot/2.1'],
    'curl' => ['curl/8.4.0'],
    'a link preview' => ['WhatsApp/2.23 LinkPreview'],
    'an empty user agent' => [''],
]);

it('redirects a click to the address saved on the campaign and counts it', function () {
    shopper('Ada');
    [, $recipients] = trackedCampaign();
    $token = trackingToken(campaignEmails()[0][0]->message['button']['url']);

    http()->get("/api/v1/t/c/{$token}", ['User-Agent' => TRACKING_BROWSER])->assertRedirect('https://icecream.example.com/products');
    http()->get("/api/v1/t/c/{$token}", ['User-Agent' => TRACKING_BROWSER])->assertRedirect('https://icecream.example.com/products');

    $row = $recipients[0]->fresh();
    expect($row->click_count)->toBe(2)->and($row->first_clicked_at)->not->toBeNull()
        ->and($row->open_count)->toBe(1)->and($row->first_opened_at)->not->toBeNull();
});

it('can never be turned into a redirect to another site', function () {
    shopper('Ada');
    trackedCampaign();
    $token = trackingToken(campaignEmails()[0][0]->message['button']['url']);

    foreach (['?url=https://evil.example.com', '?to=//evil.example.com', '?redirect=https%3A%2F%2Fevil.example.com'] as $query) {
        http()->get("/api/v1/t/c/{$token}{$query}", ['User-Agent' => TRACKING_BROWSER])->assertRedirect('https://icecream.example.com/products');
    }
});

it('has nothing to redirect to for a campaign without a button', function () {
    shopper('Ada');
    $id = sendCampaign([], 'track-key-0009')->assertCreated()->json('data.id');
    $recipient = EmailCampaignRecipient::withoutTenantScope()->where('email_campaign_id', $id)->first();

    http()->get('/api/v1/t/c/'.trackingToken(EmailTracking::clickUrl($recipient->id)), ['User-Agent' => TRACKING_BROWSER])->assertNotFound();
});

it('does not track test emails', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/email-campaigns/test', ['subject' => 'Preview me', 'body' => "Hi {first_name}\n\nBody text here", 'button_label' => 'Shop', 'button_url' => 'https://shop.example.com'])->assertOk();

    $message = campaignEmails()[0][0]->message;
    expect($message['tracking_pixel'])->toBeNull()->and($message['button']['url'])->toBe('https://shop.example.com');
});

it('tracks scheduled campaigns according to the setting when they go out', function () {
    shopper('Ada');
    scheduleCampaign(extra: ['button_label' => 'Shop', 'button_url' => 'https://shop.example.com'])->assertCreated();
    $this->travel(3)->hours();
    runScheduled();

    expect(EmailCampaign::withoutTenantScope()->first()->tracked)->toBeTrue()->and(campaignEmails()[0][0]->message['tracking_pixel'])->toContain('/api/v1/t/o/');
});

it('renders the hidden image in the email', function () {
    $content = CampaignMessage::build(['store' => 'Scoops', 'accent' => '#5b3df5', 'supportEmail' => null], 'Hello', 'Some text here for you', 'Shop', 'https://shop.example.com', 'Ada', null, null, 'rec-1');

    $html = view('emails.branded', ['content' => $content])->render();

    expect($html)->toContain('<img src="'.EmailTracking::pixelUrl('rec-1').'" width="1" height="1"');
});

// ---- the merchant's view --------------------------------------------------------------------

it('shows how many people opened and clicked, each counted once', function () {
    shopper('Ada');
    shopper('Bola');
    shopper('Chidi');
    [$id, $recipients] = trackedCampaign();
    $ada = $recipients->firstWhere('email', 'ada@mail.test');
    $bola = $recipients->firstWhere('email', 'bola@mail.test');
    EmailCampaignRecipient::withoutTenantScope()->whereKey($ada->id)->update(['open_count' => 5, 'click_count' => 3]);
    EmailCampaignRecipient::withoutTenantScope()->whereKey($bola->id)->update(['open_count' => 1]);

    $this->getJson("/api/v1/email-campaigns/{$id}")->assertOk()->assertJsonPath('data.tracked', true)->assertJsonPath('data.recipientsCount', 3)
        ->assertJsonPath('data.openedCount', 2)->assertJsonPath('data.clickedCount', 1);
    $this->getJson('/api/v1/email-campaigns')->assertJsonPath('data.0.openedCount', 2)->assertJsonPath('data.0.clickedCount', 1);
});

it('lists each recipient with what they did, and filters by it', function () {
    shopper('Ada');
    shopper('Bola');
    shopper('Chidi');
    [$id, $recipients] = trackedCampaign();
    $now = now();
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'ada@mail.test')->id)->update(['open_count' => 2, 'first_opened_at' => $now->copy()->subHour(), 'last_opened_at' => $now, 'click_count' => 1, 'first_clicked_at' => $now, 'last_clicked_at' => $now]);
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'bola@mail.test')->id)->update(['open_count' => 1, 'first_opened_at' => $now, 'last_opened_at' => $now]);
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'chidi@mail.test')->id)->update(['status' => 'failed']);

    $emails = fn (string $status) => collect($this->getJson("/api/v1/email-campaigns/{$id}/recipients?status={$status}")->assertOk()->json('data'))->pluck('email')->all();

    expect($emails('all'))->toBe(['ada@mail.test', 'bola@mail.test', 'chidi@mail.test'])
        ->and($emails('opened'))->toBe(['ada@mail.test', 'bola@mail.test'])->and($emails('clicked'))->toBe(['ada@mail.test'])
        ->and($emails('not_opened'))->toBe([])->and($emails('failed'))->toBe(['chidi@mail.test']);

    $row = $this->getJson("/api/v1/email-campaigns/{$id}/recipients?search=ada")->assertJsonCount(1, 'data')->json('data.0');
    expect($row)->toMatchArray(['email' => 'ada@mail.test', 'firstName' => 'Ada', 'status' => 'sent', 'openCount' => 2, 'clickCount' => 1])->and($row['firstOpenedAt'])->not->toBeNull();
});

it('lists people who got the email but have not opened it', function () {
    shopper('Ada');
    shopper('Bola');
    [$id, $recipients] = trackedCampaign();
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'ada@mail.test')->id)->update(['open_count' => 1]);

    expect(collect($this->getJson("/api/v1/email-campaigns/{$id}/recipients?status=not_opened")->json('data'))->pluck('email')->all())->toBe(['bola@mail.test']);
});

it('keeps the recipient list inside the store and for people who manage customers', function () {
    shopper('Ada');
    [$mine] = trackedCampaign();
    $theirs = EmailCampaign::factory()->forTenant($this->b['tenant'])->create();

    $this->getJson("/api/v1/email-campaigns/{$theirs->id}/recipients")->assertNotFound();
    $this->getJson("/api/v1/email-campaigns/{$mine}/recipients?status=everything")->assertUnprocessable();

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->getJson("/api/v1/email-campaigns/{$mine}/recipients")->assertForbidden();
    http()->getJson("/api/v1/email-campaigns/{$mine}/recipients")->assertUnauthorized();
});

it('validates the tracking setting', function () {
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['campaign_tracking' => 'sometimes']])->assertUnprocessable();
});

// ---- exporting results ----------------------------------------------------------------------

/** @return list<list<string|null>> */
function csvRows(string $csv): array
{
    return array_map('str_getcsv', array_filter(explode("\n", trim($csv)), fn ($line) => $line !== ''));
}

it('exports each recipient with what they did, as a spreadsheet', function () {
    shopper('Ada');
    shopper('Bola');
    shopper('Chidi');
    [$id, $recipients] = trackedCampaign('export-key-0001');
    $opened = Carbon\Carbon::parse('2026-10-12 09:30:00', 'UTC');
    $clicked = Carbon\Carbon::parse('2026-10-12 09:31:15', 'UTC');
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'ada@mail.test')->id)->update(['open_count' => 3, 'first_opened_at' => $opened, 'last_opened_at' => $opened->copy()->addHour(), 'click_count' => 2, 'first_clicked_at' => $clicked, 'last_clicked_at' => $clicked->copy()->addMinute()]);
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'bola@mail.test')->id)->update(['open_count' => 1, 'first_opened_at' => $opened, 'last_opened_at' => $opened]);
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'chidi@mail.test')->id)->update(['status' => 'failed', 'sent_at' => null]);

    $response = $this->get("/api/v1/email-campaigns/{$id}/export")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $rows = csvRows($response->streamedContent());

    expect($response->headers->get('Content-Disposition'))->toContain('campaign-our-summer-offers-results.csv')
        ->and($rows[0])->toBe(['Email', 'First name', 'Delivery', 'Sent at (UTC)', 'Opened', 'Times opened', 'First opened (UTC)', 'Last opened (UTC)', 'Clicked', 'Times clicked', 'First clicked (UTC)', 'Last clicked (UTC)'])
        ->and($rows)->toHaveCount(4);

    $byEmail = collect(array_slice($rows, 1))->keyBy(0);
    expect($byEmail['ada@mail.test'])->toMatchArray([1 => 'Ada', 2 => 'sent', 4 => 'yes', 5 => '3', 6 => '2026-10-12T09:30:00+00:00', 7 => '2026-10-12T10:30:00+00:00', 8 => 'yes', 9 => '2', 10 => '2026-10-12T09:31:15+00:00', 11 => '2026-10-12T09:32:15+00:00'])
        ->and($byEmail['bola@mail.test'])->toMatchArray([4 => 'yes', 5 => '1', 8 => 'no', 9 => '0', 10 => ''])
        ->and($byEmail['chidi@mail.test'])->toMatchArray([2 => 'failed', 3 => '', 4 => 'no', 5 => '0']);
});

it('exports only what the filter and search on screen show', function () {
    shopper('Ada');
    shopper('Bola');
    [$id, $recipients] = trackedCampaign('export-key-0002');
    EmailCampaignRecipient::withoutTenantScope()->whereKey($recipients->firstWhere('email', 'ada@mail.test')->id)->update(['open_count' => 1, 'click_count' => 1]);
    $emails = fn (string $query) => collect(array_slice(csvRows($this->get("/api/v1/email-campaigns/{$id}/export{$query}")->assertOk()->streamedContent()), 1))->pluck(0)->all();

    expect($emails(''))->toBe(['ada@mail.test', 'bola@mail.test'])
        ->and($emails('?status=clicked'))->toBe(['ada@mail.test'])
        ->and($emails('?status=not_opened'))->toBe(['bola@mail.test'])
        ->and($emails('?search=bol'))->toBe(['bola@mail.test'])
        ->and($emails('?status=failed'))->toBe([]);
});

it('leaves open and click cells empty for a campaign that was not measured, instead of showing zeros', function () {
    shopper('Ada');
    $this->actingAs($this->a['owner'], 'sanctum')->putJson('/api/v1/store/settings', ['notifications' => ['campaign_tracking' => false]])->assertOk();
    app('auth')->forgetGuards();
    [$id] = trackedCampaign('export-key-0003');

    $row = csvRows($this->get("/api/v1/email-campaigns/{$id}/export")->streamedContent())[1];

    expect($row[0])->toBe('ada@mail.test')->and($row[2])->toBe('sent')->and([$row[4], $row[5], $row[8], $row[9]])->toBe(['', '', '', '']);
});

it('stops names typed by customers from running as formulas', function () {
    shopper('Ada');
    [$id] = trackedCampaign('export-key-0004');
    EmailCampaignRecipient::withoutTenantScope()->update(['first_name' => '=HYPERLINK("http://evil.test","click")']);

    $row = csvRows($this->get("/api/v1/email-campaigns/{$id}/export")->streamedContent())[1];

    expect($row[1])->toBe("'=HYPERLINK(\"http://evil.test\",\"click\")");
});

it('names the file after the campaign, safely', function () {
    shopper('Ada');
    $id = sendCampaign(['subject' => '  50% OFF!!  /  "Summer" ..\\ sale  '], 'export-key-0005')->assertCreated()->json('data.id');
    $symbols = sendCampaign(['subject' => '***'], 'export-key-0006')->assertCreated()->json('data.id');

    expect($this->get("/api/v1/email-campaigns/{$id}/export")->headers->get('Content-Disposition'))->toContain('campaign-50-off-summer-sale-results.csv')
        ->and($this->get("/api/v1/email-campaigns/{$symbols}/export")->headers->get('Content-Disposition'))->toContain('campaign-results-results.csv');
});

it('keeps exports inside the store and for people who manage customers', function () {
    shopper('Ada');
    [$mine] = trackedCampaign('export-key-0007');
    $theirs = EmailCampaign::factory()->forTenant($this->b['tenant'])->create();

    $this->get("/api/v1/email-campaigns/{$theirs->id}/export")->assertNotFound();
    $this->getJson("/api/v1/email-campaigns/{$mine}/export?status=everything")->assertUnprocessable();

    $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();
    $this->actingAs($staff, 'sanctum')->get("/api/v1/email-campaigns/{$mine}/export")->assertForbidden();
    http()->getJson("/api/v1/email-campaigns/{$mine}/export")->assertUnauthorized();
});

it('records one consistent time for a first open or click, so first is never after last', function () {
    shopper('Ada');
    [, $recipients] = trackedCampaign('export-key-0008');
    $message = campaignEmails()[0][0]->message;
    $this->travelTo(Carbon\Carbon::parse('2026-10-12 09:30:00.900000', 'UTC'));

    http()->get('/api/v1/t/c/'.trackingToken($message['button']['url']), ['User-Agent' => TRACKING_BROWSER]);

    $row = $recipients[0]->fresh();
    expect($row->first_clicked_at->equalTo($row->last_clicked_at))->toBeTrue()->and($row->first_opened_at->equalTo($row->last_opened_at))->toBeTrue()
        ->and($row->first_opened_at->toDateTimeString())->toBe('2026-10-12 09:30:00');
});
