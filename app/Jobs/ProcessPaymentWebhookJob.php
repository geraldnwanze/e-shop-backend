<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatus;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries only the webhook-event id. It re-enters the tenant from the stored
 * (signature-verified) event, then confirms the payment against the provider's
 * own API. The webhook body is never treated as proof of payment.
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function __construct(public readonly string $webhookEventId) {}

    public function handle(TenantContext $context, PaymentService $payments): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);

        if ($event === null || $event->status === WebhookEventStatus::Processed) {
            return;
        }

        $tenant = Tenant::query()->find($event->tenant_id);

        if ($tenant === null) {
            $event->update(['status' => WebhookEventStatus::Ignored, 'error' => 'tenant_missing']);

            return;
        }

        $context->runAs($tenant, function () use ($event, $payments): void {
            // Tenant-scoped lookup: a reference belonging to another tenant simply does not exist here.
            $payment = Payment::query()->where('reference', $event->reference)->where('provider', $event->provider->value)->first();

            if ($payment === null) {
                $event->update(['status' => WebhookEventStatus::Ignored, 'error' => 'unknown_reference', 'processed_at' => now()]);

                return;
            }

            $payments->verifyAndConfirm($payment);
            $event->update(['status' => WebhookEventStatus::Processed, 'error' => null, 'processed_at' => now()]);
        });
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::query()->whereKey($this->webhookEventId)->update(['status' => WebhookEventStatus::Failed, 'error' => substr($e->getMessage(), 0, 250)]);

        Log::error('Payment webhook processing failed', ['webhook_event_id' => $this->webhookEventId, 'error' => $e->getMessage()]);
    }
}
