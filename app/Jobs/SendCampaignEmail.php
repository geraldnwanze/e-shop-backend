<?php

namespace App\Jobs;

use App\Models\EmailCampaignRecipient;
use App\Models\Tenant;
use App\Services\Campaigns\CampaignService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a campaign email to one recipient, inside that store's tenant context.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $tenantId, public readonly string $recipientId) {}

    public function handle(TenantContext $context, CampaignService $campaigns): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runAs($tenant, function () use ($campaigns): void {
            $recipient = EmailCampaignRecipient::query()->with('campaign')->find($this->recipientId);

            if ($recipient !== null) {
                $campaigns->deliver($recipient);
            }
        });
    }

    /**
     * After the last retry the recipient is marked failed, so the campaign can finish.
     */
    public function failed(Throwable $e): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function () use ($e): void {
            $recipient = EmailCampaignRecipient::query()->with('campaign')->find($this->recipientId);

            if ($recipient !== null) {
                app(CampaignService::class)->markFailed($recipient, $e->getMessage());
            }
        });
    }
}
