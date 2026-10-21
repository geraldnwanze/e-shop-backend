<?php

namespace App\Services\Billing;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\BaseApiException;
use App\Exceptions\PaymentException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StoreMessageNotification;
use App\Services\Platform\PlatformSettings;
use App\Services\Platform\SubscriptionService;
use App\Support\Notifications\StoreMailContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Self-serve plan billing: free trials, paying for a plan, automatic renewals with retries,
 * and dropping to the free plan when a trial or payment lapses.
 *
 * Money is only trusted after Paystack confirms it, and each charge is recorded as an invoice
 * first so a lost webhook or a repeated callback can never double-extend a period.
 */
class BillingService
{
    private const ACCENT = '#5b3df5';

    public function __construct(
        private readonly BillingGateway $gateway,
        private readonly BillingPricing $pricing,
        private readonly SubscriptionService $subscriptions,
        private readonly PlatformSettings $settings,
    ) {}

    public function current(Tenant $tenant): ?Subscription
    {
        return Subscription::withoutTenantScope()->where('tenant_id', $tenant->getKey())->with('plan')->latest()->latest('id')->first();
    }

    /**
     * New stores try the best paid plan for free, with no card. Nothing happens when trials are off
     * or no paid plan exists yet.
     */
    public function startTrial(Tenant $tenant): ?Subscription
    {
        $days = (int) $this->settings->get('billing.trial_days');
        $plan = Plan::query()->where('is_active', true)->where('price', '>', 0)->orderByDesc('price')->first();

        if ($days <= 0 || $plan === null) {
            return null;
        }

        return $this->subscriptions->assign($tenant, $plan, SubscriptionStatus::Trialing, now()->addDays($days));
    }

    /**
     * Whether an existing store should be offered a trial: never had one, not paying for a plan,
     * and either on no plan or only the free one. Stores an admin put on a paid plan are left alone.
     */
    public function eligibleForTrial(Tenant $tenant): bool
    {
        if (! $tenant->isActive()) {
            return false;
        }

        $hadTrial = Subscription::withoutTenantScope()->where('tenant_id', $tenant->getKey())->where(
            fn ($q) => $q->whereNotNull('trial_ends_at')->orWhere('status', SubscriptionStatus::Trialing->value),
        )->exists();

        if ($hadTrial) {
            return false;
        }

        $current = $this->current($tenant);

        return $current === null || (! $current->isSelfServe() && $current->plan->price === 0);
    }

    /**
     * Tell the owner they now have a trial (used when trials are rolled out to existing stores).
     */
    public function notifyTrialStarted(Tenant $tenant, Subscription $trial): void
    {
        $this->notify($tenant, 'trial_started', 'You have a free trial', $this->message(
            'Enjoy a free trial of '.$trial->plan->name,
            "Your store now has all the {$trial->plan->name} plan features free until {$this->date($trial->trial_ends_at)}. No card needed. When it ends your store moves to the free plan unless you subscribe.",
            [['Plan', $trial->plan->name], ['Free until', $this->date($trial->trial_ends_at)]],
            ['label' => 'See plans', 'url' => $this->billingUrl()],
        ));
    }

    /**
     * Begin paying for a plan: records the invoice and returns Paystack's hosted checkout.
     *
     * @return array{invoice: SubscriptionInvoice, url: string}
     */
    public function checkout(Tenant $tenant, User $payer, Plan $plan, int $months): array
    {
        if (! $this->gateway->configured()) {
            throw new BaseApiException(ApiErrorCodeEnum::PaymentProviderError, 'Billing is not available yet. Please try again later.');
        }

        if (! $this->pricing->isPurchasable($plan) || ! $this->pricing->supports($months)) {
            throw new BaseApiException(ApiErrorCodeEnum::ValidationFailed, 'That plan or billing period is not available.');
        }

        $current = $this->current($tenant);
        $invoice = new SubscriptionInvoice([
            'plan_id' => $plan->getKey(),
            'reference' => 'bill_'.Str::lower(Str::random(24)),
            'purpose' => $current?->plan_id === $plan->getKey() && $current->isSelfServe() ? 'renewal' : 'subscribe',
            'period_months' => $months,
            'amount' => $this->pricing->amount($plan, $months),
            'currency' => strtoupper((string) config('billing.currency')),
            'status' => InvoiceStatus::Pending,
            'meta' => ['payer_email' => $payer->email, 'plan' => $plan->name],
        ]);
        $invoice->tenant_id = $tenant->getKey();
        $invoice->save();

        $url = $this->gateway->initialize(
            $invoice->reference, $payer->email, $invoice->amount, $invoice->currency,
            rtrim(config('tenancy.frontend_url'), '/').'/dashboard/billing?reference='.$invoice->reference,
            ['invoice_id' => $invoice->getKey(), 'plan' => $plan->slug, 'months' => $months],
        );

        return ['invoice' => $invoice, 'url' => $url];
    }

    /**
     * Settle an invoice from what Paystack reports. Safe to call any number of times, from the
     * return page or the webhook, in any order.
     */
    public function confirm(SubscriptionInvoice $invoice): SubscriptionInvoice
    {
        if ($invoice->status !== InvoiceStatus::Pending) {
            return $invoice;
        }

        $charge = $this->gateway->verify($invoice->reference);

        return match (true) {
            $charge->isPaid() => $this->applyPaid($invoice, $charge),
            $charge->isFailed() => $this->markFailed($invoice, $charge->message ?? 'declined'),
            default => $invoice,
        };
    }

    /**
     * Run by the scheduler: trials ending, renewals due, lapsed subscriptions, reminders.
     *
     * @return array<string, int>
     */
    public function process(): array
    {
        return [
            'trials_ended' => $this->expireTrials(),
            'renewed' => $this->renewDue(),
            'downgraded' => $this->downgradeLapsed(),
            'reminded' => $this->sendReminders(),
        ];
    }

    public function cancelAutoRenew(Tenant $tenant): Subscription
    {
        $subscription = $this->selfServe($tenant);
        $subscription->forceFill(['auto_renew' => false, 'reminder_sent_at' => null])->save();

        $this->notify($tenant, 'subscription_cancelled', 'Your subscription will end', $this->message(
            'Auto-renew is off',
            "Your {$subscription->plan->name} plan stays active until {$this->date($subscription->current_period_ends_at)}. After that your store moves to the free plan.",
            [['Plan', $subscription->plan->name], ['Access until', $this->date($subscription->current_period_ends_at)]],
            ['label' => 'Turn auto-renew back on', 'url' => $this->billingUrl()],
        ));

        return $subscription;
    }

    public function resumeAutoRenew(Tenant $tenant): Subscription
    {
        $subscription = $this->selfServe($tenant);

        if ($subscription->authorization_code === null) {
            throw BaseApiException::conflict('Pay once to save a card before turning auto-renew on.');
        }

        $subscription->forceFill(['auto_renew' => true])->save();

        return $subscription;
    }

    public function expireTrials(): int
    {
        $ended = Subscription::withoutTenantScope()->where('status', SubscriptionStatus::Trialing->value)->where('trial_ends_at', '<=', now())->get();

        foreach ($ended as $subscription) {
            $this->downgrade($subscription, 'trial_ended');
        }

        return $ended->count();
    }

    /**
     * Charge every saved card whose period has ended.
     */
    public function renewDue(): int
    {
        $due = Subscription::withoutTenantScope()->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->whereNotNull('billing_period')->where('auto_renew', true)->whereNotNull('authorization_code')
            ->where('current_period_ends_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->get();

        $renewed = 0;

        foreach ($due as $subscription) {
            $renewed += $this->renew($subscription) ? 1 : 0;
        }

        return $renewed;
    }

    /**
     * Cancelled subscriptions whose paid time is over, and unpaid ones past the grace period.
     */
    public function downgradeLapsed(): int
    {
        $grace = (int) config('billing.grace_days');
        $lapsed = Subscription::withoutTenantScope()->with('plan')->whereNotNull('billing_period')->where(function ($q) use ($grace) {
            $q->where(fn ($q) => $q->where('status', SubscriptionStatus::Active->value)->where('auto_renew', false)->where('current_period_ends_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', SubscriptionStatus::PastDue->value)->where('current_period_ends_at', '<=', now()->subDays($grace)))
                ->orWhere(fn ($q) => $q->where('status', SubscriptionStatus::Active->value)->where('auto_renew', true)->whereNull('authorization_code')->where('current_period_ends_at', '<=', now()));
        })->get();

        foreach ($lapsed as $subscription) {
            $this->downgrade($subscription, $subscription->status === SubscriptionStatus::PastDue ? 'payment_failed' : 'ended');
        }

        return $lapsed->count();
    }

    public function sendReminders(): int
    {
        $window = now()->addDays((int) config('billing.reminder_days'));
        $sent = 0;

        $trials = Subscription::withoutTenantScope()->with(['plan', 'tenant'])->where('status', SubscriptionStatus::Trialing->value)
            ->whereNull('reminder_sent_at')->where('trial_ends_at', '>', now())->where('trial_ends_at', '<=', $window)->get();

        foreach ($trials as $subscription) {
            $this->notify($subscription->tenant, 'trial_ending', 'Your free trial ends soon', $this->message(
                'Your trial ends soon',
                "Your free trial of the {$subscription->plan->name} plan ends on {$this->date($subscription->trial_ends_at)}. Choose a plan to keep your features, or your store moves to the free plan.",
                [['Trial ends', $this->date($subscription->trial_ends_at)]],
                ['label' => 'Choose a plan', 'url' => $this->billingUrl()],
            ));
            $subscription->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        $renewals = Subscription::withoutTenantScope()->with(['plan', 'tenant'])->where('status', SubscriptionStatus::Active->value)->whereNotNull('billing_period')
            ->where('auto_renew', true)->whereNotNull('authorization_code')->whereNull('reminder_sent_at')
            ->where('current_period_ends_at', '>', now())->where('current_period_ends_at', '<=', $window)->get();

        foreach ($renewals as $subscription) {
            $amount = StoreMailContext::money($this->pricing->amount($subscription->plan, $subscription->billing_period), strtoupper((string) config('billing.currency')));

            $this->notify($subscription->tenant, 'renewal_upcoming', 'Your plan renews soon', $this->message(
                'Your plan renews soon',
                "We will charge {$amount} to your saved card on {$this->date($subscription->current_period_ends_at)} to renew your {$subscription->plan->name} plan.",
                [['Plan', $subscription->plan->name], ['Amount', $amount], ['Renews on', $this->date($subscription->current_period_ends_at)]],
                ['label' => 'Manage billing', 'url' => $this->billingUrl()],
            ));
            $subscription->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    private function renew(Subscription $subscription): bool
    {
        $plan = $subscription->plan;

        if (! $this->pricing->isPurchasable($plan) || ! $this->pricing->supports($subscription->billing_period) || ! $this->gateway->configured()) {
            return false;
        }

        $hasRecentAttempt = SubscriptionInvoice::withoutTenantScope()->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Pending->value)->where('created_at', '>=', now()->subHour())->exists();

        if ($hasRecentAttempt) {
            return false;
        }

        $invoice = new SubscriptionInvoice([
            'subscription_id' => $subscription->getKey(),
            'plan_id' => $plan->getKey(),
            'reference' => 'bill_'.Str::lower(Str::random(24)),
            'purpose' => 'renewal',
            'period_months' => $subscription->billing_period,
            'amount' => $this->pricing->amount($plan, $subscription->billing_period),
            'currency' => strtoupper((string) config('billing.currency')),
            'status' => InvoiceStatus::Pending,
            'meta' => ['payer_email' => $subscription->payer_email, 'plan' => $plan->name, 'auto' => true],
        ]);
        $invoice->tenant_id = $subscription->tenant_id;
        $invoice->save();

        try {
            $charge = $this->gateway->chargeAuthorization((string) $subscription->authorization_code, (string) $subscription->payer_email, $invoice->amount, $invoice->currency, $invoice->reference);
        } catch (PaymentException) {
            // Paystack unreachable: not the merchant's fault, so it neither counts as a failed attempt nor notifies them.
            $invoice->update(['status' => InvoiceStatus::Failed, 'failure_reason' => 'gateway_error']);

            return false;
        }

        if ($charge->isPaid()) {
            $this->applyPaid($invoice, $charge);

            return true;
        }

        if ($charge->isFailed()) {
            $this->markFailed($invoice, $charge->message ?? 'declined');
            $this->recordRenewalFailure($subscription);
        }

        return false;
    }

    private function applyPaid(SubscriptionInvoice $invoice, BillingCharge $charge): SubscriptionInvoice
    {
        $settled = DB::transaction(function () use ($invoice, $charge): ?SubscriptionInvoice {
            $locked = SubscriptionInvoice::withoutTenantScope()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Pending) {
                return null; // already settled by the other path (webhook vs return page)
            }

            if ($charge->amount !== $locked->amount || $charge->currency !== $locked->currency) {
                Log::error('Billing amount mismatch', ['invoice' => $locked->id, 'expected' => $locked->amount, 'received' => $charge->amount]);
                $locked->update(['status' => InvoiceStatus::Failed, 'failure_reason' => 'amount_mismatch']);

                return null;
            }

            $tenant = Tenant::query()->findOrFail($locked->tenant_id);
            $plan = Plan::query()->findOrFail($locked->plan_id);
            $current = $this->current($tenant);
            $sameLivePlan = $current !== null && $current->plan_id === $plan->getKey() && $current->isSelfServe()
                && in_array($current->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);

            $start = $sameLivePlan && $current->current_period_ends_at?->isFuture() ? $current->current_period_ends_at : now();
            $end = $start->copy()->addMonths($locked->period_months);

            $subscription = $sameLivePlan ? $current : $this->subscriptions->assign($tenant, $plan, SubscriptionStatus::Active);
            $attributes = [
                'status' => SubscriptionStatus::Active,
                'billing_period' => $locked->period_months,
                'auto_renew' => true,
                'current_period_ends_at' => $end,
                'trial_ends_at' => null,
                'failed_attempts' => 0,
                'next_attempt_at' => null,
                'reminder_sent_at' => null,
            ];

            if (! empty($locked->meta['payer_email'])) {
                $attributes['payer_email'] = $locked->meta['payer_email'];
            }

            if ($charge->card !== null) {
                $attributes += ['authorization_code' => $charge->card['code'], 'card_brand' => $charge->card['brand'], 'card_last4' => $charge->card['last4']];
            }

            $subscription->forceFill($attributes)->save();

            $locked->forceFill([
                'status' => InvoiceStatus::Paid, 'paid_at' => now(), 'provider_reference' => $charge->providerReference,
                'subscription_id' => $subscription->getKey(), 'period_starts_at' => $start, 'period_ends_at' => $end,
            ])->save();

            return $locked;
        });

        if ($settled === null) {
            return $invoice->refresh();
        }

        $tenant = Tenant::query()->findOrFail($settled->tenant_id);
        $amount = StoreMailContext::money($settled->amount, $settled->currency);
        $this->notify($tenant, 'subscription_receipt', 'Payment received', $this->message(
            'Payment received',
            "Thank you! Your {$settled->meta['plan']} plan is active until {$this->date($settled->period_ends_at)}.",
            [['Plan', $settled->meta['plan']], ['Billing period', $settled->period_months === 1 ? 'Monthly' : "Every {$settled->period_months} months"], ['Reference', $settled->reference]],
            ['label' => 'View billing', 'url' => $this->billingUrl()],
            totals: [['Amount paid', $amount]],
        ));

        return $settled;
    }

    private function markFailed(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        $invoice->forceFill(['status' => InvoiceStatus::Failed, 'failure_reason' => Str::limit($reason, 250, '')])->save();

        return $invoice;
    }

    private function recordRenewalFailure(Subscription $subscription): void
    {
        $attempts = $subscription->failed_attempts + 1;
        $schedule = (array) config('billing.retry_after_days');
        $retryDay = $schedule[$attempts - 1] ?? null;
        $retryAt = $retryDay === null ? null : $subscription->current_period_ends_at->copy()->addDays($retryDay);
        $cutoff = $subscription->current_period_ends_at->copy()->addDays((int) config('billing.grace_days'));

        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'failed_attempts' => $attempts,
            'next_attempt_at' => $retryAt !== null && $retryAt->lt($cutoff) ? $retryAt : null,
        ])->save();

        $next = $subscription->next_attempt_at;
        $this->notify($subscription->tenant ?? Tenant::query()->findOrFail($subscription->tenant_id), 'renewal_failed', 'We could not renew your plan', $this->message(
            'We could not charge your card',
            $next
                ? "The payment for your {$subscription->plan->name} plan did not go through. We will try again on {$this->date($next)}. Update your card to avoid losing your plan features."
                : "The payment for your {$subscription->plan->name} plan did not go through. Pay by {$this->date($cutoff)} to keep your plan; after that your store moves to the free plan.",
            [['Plan', $subscription->plan->name], ['Keep access until', $this->date($cutoff)]],
            ['label' => 'Update payment', 'url' => $this->billingUrl()],
        ));
    }

    /**
     * Move a store to the free plan. The paid subscription is cancelled and the merchant is told why.
     */
    private function downgrade(Subscription $subscription, string $reason): void
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);
        $previous = $subscription->plan->name;
        $free = Plan::query()->where('is_active', true)->where('price', 0)->orderBy('position')->first();

        if ($free !== null) {
            $this->subscriptions->assign($tenant, $free, SubscriptionStatus::Active);
        } else {
            $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()])->save();
        }

        [$subject, $heading, $intro] = match ($reason) {
            'trial_ended' => ['Your free trial has ended', 'Your free trial has ended', "Your trial of the {$previous} plan is over, so your store is now on the free plan. Choose a plan any time to get your features back."],
            'payment_failed' => ['Your plan has ended', 'Your plan has ended', "We could not renew your {$previous} plan, so your store is now on the free plan. Nothing is deleted; subscribe again to restore your features."],
            default => ['Your plan has ended', 'Your plan has ended', "Your {$previous} plan has ended, so your store is now on the free plan. Subscribe again any time."],
        };

        $this->notify($tenant, $reason === 'trial_ended' ? 'trial_ended' : 'plan_ended', $subject, $this->message($heading, $intro, [], ['label' => 'Choose a plan', 'url' => $this->billingUrl()]));
    }

    private function selfServe(Tenant $tenant): Subscription
    {
        $subscription = $this->current($tenant);

        if ($subscription === null || ! $subscription->isSelfServe() || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            throw BaseApiException::conflict('There is no paid subscription to change.');
        }

        return $subscription;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $details
     * @param  array{label: string, url: string}|null  $button
     * @param  list<array{0: string, 1: string}>  $totals
     * @return array<string, mixed>
     */
    private function message(string $heading, string $intro, array $details = [], ?array $button = null, array $totals = []): array
    {
        return array_filter([
            'store' => (string) $this->settings->get('platform.name'),
            'accent' => self::ACCENT,
            'preheader' => $intro,
            'heading' => $heading,
            'intro' => $intro,
            'details' => $details,
            'totals' => $totals,
            'button' => $button,
            'footer' => $this->settings->get('platform.support_email') ? 'Questions? Write to '.$this->settings->get('platform.support_email').'.' : null,
            'reply_to' => $this->settings->get('platform.support_email'),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function notify(Tenant $tenant, string $kind, string $subject, array $content): void
    {
        $owner = User::query()->where('tenant_id', $tenant->getKey())->where('role', UserRole::MerchantOwner->value)->orderBy('created_at')->value('email');

        if ($owner !== null) {
            Notification::route('mail', $owner)->notify(new StoreMessageNotification($kind, $subject, $content));
        }
    }

    private function billingUrl(): string
    {
        return rtrim(config('tenancy.frontend_url'), '/').'/dashboard/billing';
    }

    private function date(?CarbonInterface $date): string
    {
        return $date?->format('j M Y') ?? '';
    }
}
