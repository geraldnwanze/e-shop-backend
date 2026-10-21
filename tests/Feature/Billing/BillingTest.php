<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Services\Billing\BillingService;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['billing.paystack_secret_key' => 'sk_test_platform']);
    $this->free = Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price' => 0, 'position' => 0, 'limits' => ['products' => 25, 'custom_domains' => 0]]);
    $this->starter = Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price' => 500000, 'position' => 1, 'limits' => ['products' => 500, 'custom_domains' => 1]]);
    $this->pro = Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'price' => 1500000, 'position' => 2, 'limits' => ['products' => null, 'custom_domains' => 5]]);
    $this->a = createMerchant('icecream');
    $this->b = createMerchant('fashion');
});

/**
 * Fake the platform's Paystack account. Verification and card charges echo what was asked for
 * unless overridden, so tests state only what differs.
 */
function fakePlatformPaystack(?int $verifyAmount = null, string $status = 'success', string $chargeStatus = 'success', ?array $authorization = null): void
{
    $card = $authorization ?? ['authorization_code' => 'AUTH_card1', 'reusable' => true, 'brand' => 'visa', 'last4' => '4081'];

    refakeHttp([
        'api.paystack.co/transaction/initialize' => fn (Request $r) => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/billing', 'reference' => $r['reference']]]),
        'api.paystack.co/transaction/verify/*' => function (Request $r) use ($verifyAmount, $status, $card) {
            $reference = basename(parse_url($r->url(), PHP_URL_PATH));
            $invoice = SubscriptionInvoice::withoutTenantScope()->where('reference', $reference)->first();

            return Http::response(['status' => true, 'data' => ['id' => 77, 'status' => $status, 'reference' => $reference, 'amount' => $verifyAmount ?? $invoice?->amount, 'currency' => 'NGN', 'gateway_response' => $status === 'success' ? 'Successful' : 'Declined', 'authorization' => $card]]);
        },
        'api.paystack.co/transaction/charge_authorization' => fn (Request $r) => Http::response(['status' => true, 'data' => ['id' => 88, 'status' => $chargeStatus, 'reference' => $r['reference'], 'amount' => $r['amount'], 'currency' => 'NGN', 'gateway_response' => $chargeStatus === 'success' ? 'Approved' : 'Insufficient funds', 'authorization' => $card]]),
    ]);
}

function billingEmails(?string $kind = null): array
{
    $sent = [];

    foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byClass) {
        foreach ($byClass[StoreMessageNotification::class] ?? [] as $row) {
            if ($kind === null || $row['notification']->kind === $kind) {
                $sent[] = [$row['notification'], $row['notifiable']->routes['mail']];
            }
        }
    }

    return $sent;
}

function currentSubscription(?Model $tenant = null): ?Subscription
{
    return Subscription::withoutTenantScope()->where('tenant_id', ($tenant ?? test()->a['tenant'])->id)->latest()->latest('id')->first();
}

function payFor(Plan $plan, int $months = 1, ?User $payer = null): SubscriptionInvoice
{
    $owner = $payer ?? test()->a['owner'];
    $reference = test()->actingAs($owner, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $plan->id, 'months' => $months])->assertCreated()->json('data.reference');
    test()->postJson('/api/v1/billing/verify', ['reference' => $reference])->assertOk();

    return SubscriptionInvoice::withoutTenantScope()->where('reference', $reference)->firstOrFail();
}

/**
 * A paid, self-serve subscription whose period ended an hour ago (renewal is due now).
 */
function dueSubscription(array $overrides = []): Subscription
{
    fakePlatformPaystack();
    payFor(test()->starter);
    $subscription = currentSubscription();
    $subscription->forceFill($overrides + ['current_period_ends_at' => now()->subHour()])->save();

    return $subscription->refresh();
}

describe('trials', function () {
    it('starts every new store on a free trial of the best paid plan', function () {
        $this->postJson('/api/v1/auth/register', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'secret-pass1', 'password_confirmation' => 'secret-pass1', 'store_name' => 'Brand', 'store_slug' => 'brand'])->assertCreated();

        $tenant = Tenant::query()->where('slug', 'brand')->first();
        $subscription = currentSubscription($tenant);

        expect($subscription->plan_id)->toBe($this->pro->id)->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and((int) round(now()->diffInDays($subscription->trial_ends_at)))->toBe(14)
            ->and($subscription->isSelfServe())->toBeFalse();
    });

    it('uses the trial length the platform admin sets, and skips trials at 0', function () {
        app(PlatformSettings::class)->update(['billing.trial_days' => 7]);
        $trial = app(BillingService::class)->startTrial($this->b['tenant']);
        expect((int) round(now()->diffInDays($trial->trial_ends_at)))->toBe(7);

        app(PlatformSettings::class)->update(['billing.trial_days' => 0]);
        expect(app(BillingService::class)->startTrial($this->a['tenant']))->toBeNull();
    });

    it('drops the store to the free plan when the trial ends, and tells the owner once', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);

        $this->travel(15)->days();
        Artisan::call('billing:process');
        Artisan::call('billing:process');

        $subscription = currentSubscription();
        expect($subscription->plan_id)->toBe($this->free->id)->and($subscription->status)->toBe(SubscriptionStatus::Active);
        expect(billingEmails('trial_ended'))->toHaveCount(1)->and(billingEmails('trial_ended')[0][1])->toBe($this->a['owner']->email);
    });

    it('reminds the owner once as the trial runs out', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);

        $this->travel(12)->days();
        Artisan::call('billing:process');
        Artisan::call('billing:process');

        expect(billingEmails('trial_ending'))->toHaveCount(1);
    });
});

describe('summary', function () {
    it('shows the trial, the purchasable plans with period prices, and no invoices yet', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);

        $data = $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.billingAvailable', true)->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.trialDaysLeft', 14)->assertJsonPath('data.subscription.plan.name', 'Pro')
            ->assertJsonCount(2, 'data.plans')->assertJsonCount(0, 'data.invoices')->json('data');

        $pro = collect($data['plans'])->firstWhere('slug', 'pro');
        expect(collect($pro['options'])->pluck('amount', 'months')->all())->toBe([1 => 1500000, 3 => 4050000, 12 => 14400000])
            ->and(collect($data['plans'])->pluck('slug')->all())->toBe(['starter', 'pro']); // the free plan cannot be bought
    });

    it('is only for people who can manage billing', function () {
        $staff = User::factory()->forTenant($this->a['tenant'], UserRole::MerchantStaff)->create();

        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/billing')->assertForbidden();
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 1])->assertForbidden();

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/billing')->assertUnauthorized();
    });

    it('never exposes the saved card token', function () {
        fakePlatformPaystack();
        payFor($this->starter);

        $response = $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.subscription.card.last4', '4081');

        expect($response->getContent())->not->toContain('AUTH_card1')->not->toContain('authorization');
    });
});

describe('subscribing', function () {
    it('creates an invoice for the exact period price and sends the owner to Paystack', function () {
        fakePlatformPaystack();

        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 3])->assertCreated()
            ->assertJsonPath('data.authorizationUrl', 'https://checkout.paystack.com/billing')->json('data.reference');

        $invoice = SubscriptionInvoice::withoutTenantScope()->where('reference', $reference)->first();
        expect($invoice->amount)->toBe(4050000)->and($invoice->status)->toBe(InvoiceStatus::Pending)->and($invoice->tenant_id)->toBe($this->a['tenant']->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'transaction/initialize') && $r['amount'] === 4050000 && $r['email'] === $this->a['owner']->email
            && str_contains($r['callback_url'], '/dashboard/billing?reference=') && $r->hasHeader('Authorization', 'Bearer sk_test_platform'));
    });

    it('rejects plans and periods that cannot be bought', function () {
        fakePlatformPaystack();
        $this->actingAs($this->a['owner'], 'sanctum');

        $this->postJson('/api/v1/billing/checkout', ['plan_id' => $this->free->id, 'months' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 5])->assertUnprocessable();
        $this->postJson('/api/v1/billing/checkout', ['plan_id' => 'not-a-uuid', 'months' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/billing/checkout', ['plan_id' => Plan::factory()->inactive()->create()->id, 'months' => 1])->assertUnprocessable();
        expect(SubscriptionInvoice::withoutTenantScope()->count())->toBe(0);
    });

    it('refuses to start a payment when the platform has no billing account yet', function () {
        config(['billing.paystack_secret_key' => null]);

        $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 1])->assertStatus(502);
        $this->getJson('/api/v1/billing')->assertJsonPath('data.billingAvailable', false);
    });

    it('activates the plan only after Paystack confirms, ends the trial, and saves the card', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);
        fakePlatformPaystack();

        $invoice = payFor($this->starter, 3);

        $subscription = currentSubscription();
        expect($invoice->status)->toBe(InvoiceStatus::Paid)->and($invoice->amount)->toBe(1350000)
            ->and($subscription->plan_id)->toBe($this->starter->id)->and($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->trial_ends_at)->toBeNull()->and($subscription->billing_period)->toBe(3)->and($subscription->auto_renew)->toBeTrue()
            ->and($subscription->authorization_code)->toBe('AUTH_card1')->and($subscription->card_last4)->toBe('4081')
            ->and((int) round(now()->diffInDays($subscription->current_period_ends_at)))->toBe(92)
            ->and($subscription->isSelfServe())->toBeTrue();

        $receipts = billingEmails('subscription_receipt');
        expect($receipts)->toHaveCount(1)->and($receipts[0][1])->toBe($this->a['owner']->email);
        expect((string) $receipts[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('Payment received')->toContain('13,500.00 NGN');
    });

    it('does not extend twice when the return page and the webhook both confirm', function () {
        fakePlatformPaystack();
        $invoice = payFor($this->starter);
        $endsAt = currentSubscription()->current_period_ends_at;

        $this->postJson('/api/v1/billing/verify', ['reference' => $invoice->reference])->assertOk()->assertJsonPath('data.status', 'paid');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $invoice->reference]]);
        $this->call('POST', '/api/v1/webhooks/billing/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_platform')], $body)->assertOk();

        expect(currentSubscription()->current_period_ends_at->equalTo($endsAt))->toBeTrue()->and(billingEmails('subscription_receipt'))->toHaveCount(1)
            ->and(Subscription::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->count())->toBe(1);
    });

    it('confirms through the webhook when the merchant never returns', function () {
        fakePlatformPaystack();
        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 1])->json('data.reference');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);

        $this->call('POST', '/api/v1/webhooks/billing/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_platform')], $body)->assertOk();

        expect(currentSubscription()->plan_id)->toBe($this->pro->id)->and(currentSubscription()->status)->toBe(SubscriptionStatus::Active);
    });

    it('rejects billing webhooks with a bad signature and changes nothing', function () {
        fakePlatformPaystack();
        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 1])->json('data.reference');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);

        $this->call('POST', '/api/v1/webhooks/billing/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_wrong')], $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/webhooks/billing/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnauthorized();

        expect(currentSubscription())->toBeNull();
    });

    it('ignores webhooks for references that are not subscription invoices', function () {
        fakePlatformPaystack();
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'pay_someorder']]);

        $this->call('POST', '/api/v1/webhooks/billing/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_platform')], $body)->assertOk();
        Http::assertNothingSent();
    });

    it('does not grant the plan when Paystack reports a different amount', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);
        fakePlatformPaystack(verifyAmount: 100);

        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 12])->json('data.reference');
        $this->postJson('/api/v1/billing/verify', ['reference' => $reference])->assertOk()->assertJsonPath('data.status', 'failed');

        expect(currentSubscription()->status)->toBe(SubscriptionStatus::Trialing)->and(billingEmails('subscription_receipt'))->toBeEmpty()
            ->and(SubscriptionInvoice::withoutTenantScope()->first()->failure_reason)->toBe('amount_mismatch');
    });

    it('keeps the trial when the payment is declined', function () {
        app(BillingService::class)->startTrial($this->a['tenant']);
        fakePlatformPaystack(status: 'failed');

        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->starter->id, 'months' => 1])->json('data.reference');
        $this->postJson('/api/v1/billing/verify', ['reference' => $reference])->assertOk()->assertJsonPath('data.status', 'failed');

        expect(currentSubscription()->status)->toBe(SubscriptionStatus::Trialing);
    });

    it('leaves a payment pending while Paystack has not finished it', function () {
        fakePlatformPaystack(status: 'ongoing');

        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->starter->id, 'months' => 1])->json('data.reference');
        $this->postJson('/api/v1/billing/verify', ['reference' => $reference])->assertOk()->assertJsonPath('data.status', 'pending');

        expect(currentSubscription())->toBeNull();
    });

    it('does not let one store confirm another store\'s invoice', function () {
        fakePlatformPaystack();
        $reference = $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/checkout', ['plan_id' => $this->pro->id, 'months' => 1])->json('data.reference');

        $this->actingAs($this->b['owner'], 'sanctum')->postJson('/api/v1/billing/verify', ['reference' => $reference])->assertNotFound();
        expect(currentSubscription($this->b['tenant']))->toBeNull();
    });

    it('lists only the store\'s own invoices', function () {
        fakePlatformPaystack();
        payFor($this->starter);
        payFor($this->pro, payer: $this->b['owner']);

        $this->actingAs($this->a['owner'], 'sanctum')->getJson('/api/v1/billing')->assertJsonCount(1, 'data.invoices')->assertJsonPath('data.invoices.0.plan', 'Starter');
    });

    it('extends from the current end when the same plan is paid again, and replaces the plan when upgrading', function () {
        fakePlatformPaystack();
        payFor($this->starter);
        $first = currentSubscription();
        $firstEnd = $first->current_period_ends_at->copy();

        payFor($this->starter);
        expect(currentSubscription()->id)->toBe($first->id)->and(currentSubscription()->current_period_ends_at->equalTo($firstEnd->addMonth()))->toBeTrue();

        payFor($this->pro);
        $now = currentSubscription();
        expect($now->plan_id)->toBe($this->pro->id)->and($now->id)->not->toBe($first->id)->and($first->refresh()->status)->toBe(SubscriptionStatus::Cancelled);
    });
});

describe('renewals', function () {
    it('charges the saved card when the period ends and extends the plan', function () {
        $subscription = dueSubscription();

        Artisan::call('billing:process');

        $subscription->refresh();
        expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->current_period_ends_at->isFuture())->toBeTrue()
            ->and(SubscriptionInvoice::withoutTenantScope()->where('purpose', 'renewal')->where('status', 'paid')->count())->toBe(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'charge_authorization') && $r['authorization_code'] === 'AUTH_card1' && $r['amount'] === 500000 && $r['email'] === $this->a['owner']->email);
        expect(billingEmails('subscription_receipt'))->toHaveCount(2);
    });

    it('does not charge twice for one period', function () {
        dueSubscription();

        Artisan::call('billing:process');
        Artisan::call('billing:process');

        expect(SubscriptionInvoice::withoutTenantScope()->where('purpose', 'renewal')->count())->toBe(1);
    });

    it('does not charge before the period ends or when auto-renew is off', function () {
        fakePlatformPaystack();
        payFor($this->starter);
        Artisan::call('billing:process');

        expect(SubscriptionInvoice::withoutTenantScope()->where('purpose', 'renewal')->count())->toBe(0);

        currentSubscription()->forceFill(['auto_renew' => false, 'current_period_ends_at' => now()->subMinute()])->save();
        Artisan::call('billing:process');

        expect(SubscriptionInvoice::withoutTenantScope()->where('purpose', 'renewal')->count())->toBe(0);
    });

    it('marks a failed renewal past due, retries on schedule, then drops to free after the grace period', function () {
        $subscription = dueSubscription();
        $periodEnd = $subscription->current_period_ends_at->copy();
        fakePlatformPaystack(chargeStatus: 'failed');

        Artisan::call('billing:process');

        $subscription->refresh();
        expect($subscription->status)->toBe(SubscriptionStatus::PastDue)->and($subscription->failed_attempts)->toBe(1)
            ->and($subscription->next_attempt_at->equalTo($periodEnd->copy()->addDays(1)))->toBeTrue()
            ->and(billingEmails('renewal_failed'))->toHaveCount(1);

        Artisan::call('billing:process'); // too early: nothing new
        expect($subscription->refresh()->failed_attempts)->toBe(1);

        $this->travelTo($periodEnd->copy()->addDays(1)->addMinute());
        Artisan::call('billing:process');
        expect($subscription->refresh()->failed_attempts)->toBe(2)->and($subscription->next_attempt_at->equalTo($periodEnd->copy()->addDays(3)))->toBeTrue();

        $this->travelTo($periodEnd->copy()->addDays(8));
        Artisan::call('billing:process');

        expect(currentSubscription()->plan_id)->toBe($this->free->id)->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Cancelled)
            ->and(billingEmails('plan_ended'))->toHaveCount(1);
    });

    it('keeps a past-due plan working during the grace period and restores it when payment succeeds', function () {
        $subscription = dueSubscription();
        fakePlatformPaystack(chargeStatus: 'failed');
        Artisan::call('billing:process');

        expect($subscription->refresh()->isEntitled())->toBeTrue();

        fakePlatformPaystack();
        $this->travel(2)->days();
        Artisan::call('billing:process');

        expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)->and($subscription->failed_attempts)->toBe(0)->and($subscription->next_attempt_at)->toBeNull();
    });

    it('survives Paystack being unreachable without blaming the merchant', function () {
        $subscription = dueSubscription();
        refakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'down'], 503)]);

        Artisan::call('billing:process');

        expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)->and($subscription->failed_attempts)->toBe(0)->and(billingEmails('renewal_failed'))->toBeEmpty();

        fakePlatformPaystack();
        Artisan::call('billing:process');

        expect($subscription->refresh()->current_period_ends_at->isFuture())->toBeTrue();
    });

    it('reminds the owner before a renewal, once', function () {
        fakePlatformPaystack();
        payFor($this->starter);
        currentSubscription()->forceFill(['current_period_ends_at' => now()->addDays(2)])->save();

        Artisan::call('billing:process');
        Artisan::call('billing:process');

        $reminders = billingEmails('renewal_upcoming');
        expect($reminders)->toHaveCount(1);
        expect((string) $reminders[0][0]->toMail(new AnonymousNotifiable)->render())->toContain('5,000.00 NGN');
    });
});

describe('cancelling', function () {
    it('keeps the plan until the paid period ends, then moves the store to free', function () {
        fakePlatformPaystack();
        payFor($this->starter);

        $this->actingAs($this->a['owner'], 'sanctum')->postJson('/api/v1/billing/cancel')->assertOk()->assertJsonPath('data.subscription.autoRenew', false)->assertJsonPath('data.subscription.status', 'active');
        expect(billingEmails('subscription_cancelled'))->toHaveCount(1);

        Artisan::call('billing:process');
        expect(currentSubscription()->plan_id)->toBe($this->starter->id);

        $this->travel(32)->days();
        Artisan::call('billing:process');

        expect(currentSubscription()->plan_id)->toBe($this->free->id)->and(billingEmails('plan_ended'))->toHaveCount(1);
        expect(SubscriptionInvoice::withoutTenantScope()->where('purpose', 'renewal')->count())->toBe(0);
    });

    it('can turn auto-renew back on, but not without a saved card or a paid plan', function () {
        fakePlatformPaystack();
        payFor($this->starter);
        $this->actingAs($this->a['owner'], 'sanctum');

        $this->postJson('/api/v1/billing/cancel')->assertOk();
        $this->postJson('/api/v1/billing/resume')->assertOk()->assertJsonPath('data.subscription.autoRenew', true);

        app(BillingService::class)->startTrial($this->b['tenant']);
        $this->actingAs($this->b['owner'], 'sanctum')->postJson('/api/v1/billing/cancel')->assertStatus(409);
        $this->postJson('/api/v1/billing/resume')->assertStatus(409);
    });
});

describe('safety rules', function () {
    it('never auto-manages subscriptions an admin assigned', function () {
        $assigned = Subscription::factory()->forTenant($this->a['tenant'])->create(['plan_id' => $this->pro->id, 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->subMonth()]);

        Artisan::call('billing:process');

        expect($assigned->refresh()->status)->toBe(SubscriptionStatus::Active)->and($assigned->plan_id)->toBe($this->pro->id);
        Http::assertNothingSent();
    });

    it('exposes the trial length and discounts on the public plans endpoint', function () {
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonPath('data.platform.trialDays', 14)
            ->assertJsonPath('data.platform.billingPeriods', [['months' => 1, 'discountPercent' => 0], ['months' => 3, 'discountPercent' => 10], ['months' => 12, 'discountPercent' => 20]]);
    });

    it('lets the platform admin change the trial length within sensible bounds', function () {
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin, 'sanctum');

        $this->putJson('/api/v1/admin/settings', ['billing' => ['trial_days' => 30]])->assertOk()->assertJsonPath('data.billing.trial_days', 30);
        $this->putJson('/api/v1/admin/settings', ['billing' => ['trial_days' => 91]])->assertUnprocessable();
        $this->putJson('/api/v1/admin/settings', ['billing' => ['trial_days' => -1]])->assertUnprocessable();
    });
});

describe('rolling trials out to existing stores', function () {
    beforeEach(function () {
        $this->service = app(BillingService::class);
        // Store A sits on the default free plan (as every store did before trials); store B has no plan at all.
        $this->service->current($this->a['tenant']) ?? app(SubscriptionService::class)->assign($this->a['tenant'], $this->free);
    });

    it('trials stores on the free plan or no plan, and leaves everyone else alone', function () {
        $paying = createMerchant('paying');
        $assigned = createMerchant('assigned');
        $trialed = createMerchant('trialed');
        $suspended = createMerchant('suspended');
        $suspended['tenant']->update(['status' => 'suspended']);
        $subscriptions = app(SubscriptionService::class);
        $subscriptions->assign($assigned['tenant'], $this->pro);
        Subscription::factory()->forTenant($paying['tenant'])->create(['plan_id' => $this->starter->id, 'status' => SubscriptionStatus::Active, 'billing_period' => 1, 'current_period_ends_at' => now()->addMonth()]);
        $subscriptions->assign($trialed['tenant'], $this->pro, SubscriptionStatus::Trialing, now()->subDays(20));
        $subscriptions->assign($trialed['tenant'], $this->free); // its trial ended long ago

        Artisan::call('billing:start-trials');

        expect(currentSubscription($this->a['tenant'])->status)->toBe(SubscriptionStatus::Trialing)->and(currentSubscription($this->a['tenant'])->plan_id)->toBe($this->pro->id)
            ->and(currentSubscription($this->b['tenant'])->status)->toBe(SubscriptionStatus::Trialing)
            ->and(currentSubscription($paying['tenant'])->isSelfServe())->toBeTrue()->and(currentSubscription($paying['tenant'])->status)->toBe(SubscriptionStatus::Active)
            ->and(currentSubscription($assigned['tenant'])->plan_id)->toBe($this->pro->id)->and(currentSubscription($assigned['tenant'])->status)->toBe(SubscriptionStatus::Active)
            ->and(currentSubscription($trialed['tenant'])->plan_id)->toBe($this->free->id)
            ->and(currentSubscription($suspended['tenant']))->toBeNull();
    });

    it('never gives a store a second trial when run again', function () {
        Artisan::call('billing:start-trials');
        $before = Subscription::withoutTenantScope()->count();

        Artisan::call('billing:start-trials');

        expect(Subscription::withoutTenantScope()->count())->toBe($before);
    });

    it('lists what it would do without changing anything on a dry run', function () {
        Artisan::call('billing:start-trials', ['--dry-run' => true]);

        expect(Artisan::output())->toContain('would start a trial: Icecream')->toContain('Fashion');
        expect(currentSubscription($this->a['tenant'])->plan_id)->toBe($this->free->id)->and(currentSubscription($this->b['tenant']))->toBeNull();
    });

    it('only emails owners when asked to', function () {
        Artisan::call('billing:start-trials');
        expect(billingEmails('trial_started'))->toBeEmpty();

        $this->service->expireTrials(); // not due yet; nothing changes
        Subscription::withoutTenantScope()->delete();
        app(SubscriptionService::class)->assign($this->a['tenant'], $this->free);

        Artisan::call('billing:start-trials', ['--notify' => true]);

        $emails = billingEmails('trial_started');
        expect($emails)->toHaveCount(2)->and(collect($emails)->pluck(1)->sort()->values()->all())->toBe(collect([$this->a['owner']->email, $this->b['owner']->email])->sort()->values()->all());
    });

    it('does nothing when trials are switched off', function () {
        app(PlatformSettings::class)->update(['billing.trial_days' => 0]);

        expect(Artisan::call('billing:start-trials'))->toBe(1);
        expect(currentSubscription($this->b['tenant']))->toBeNull();
    });

    it('trials a store for the length of time the platform has set', function () {
        app(PlatformSettings::class)->update(['billing.trial_days' => 30]);

        Artisan::call('billing:start-trials');

        expect((int) round(now()->diffInDays(currentSubscription($this->b['tenant'])->trial_ends_at)))->toBe(30);
    });
});
