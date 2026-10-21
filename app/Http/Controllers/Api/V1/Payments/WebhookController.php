<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\ApiErrorCodeEnum;
use App\Enums\PaymentProviderType;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Payments\PaymentManager;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Group('Webhooks', weight: 30)]
class WebhookController extends Controller
{
    /**
     * POST /webhooks/{provider}/{tenant}
     *
     * The tenant comes from the URL and is authenticated by the HMAC/hash that only
     * that store's credentials can produce. Nothing in the body selects a tenant.
     */
    public function __invoke(Request $request, PaymentProviderType $provider, Tenant $tenant, PaymentManager $manager): JsonResponse
    {
        $implementation = $tenant->isActive() ? $manager->forTenant($tenant, $provider) : null;
        $payload = $request->getContent();

        $headers = collect($request->headers->all())->map(fn (array $v) => (string) ($v[0] ?? ''))->all();

        if ($implementation === null || ! $implementation->verifyWebhookSignature($payload, $headers)) {
            Log::warning('Rejected payment webhook', ['provider' => $provider->value, 'tenant_id' => $tenant->id, 'ip' => $request->ip()]);

            throw new BaseApiException(ApiErrorCodeEnum::InvalidWebhookSignature, 'Invalid signature.');
        }

        $notification = $implementation->parseWebhook($payload);

        if ($notification === null) {
            return ApiResponse::success(['received' => true, 'ignored' => true], 'Event ignored.');
        }

        try {
            // Own savepoint: a duplicate-event violation must not poison an enclosing PostgreSQL transaction.
            $event = DB::transaction(fn () => WebhookEvent::create([
                'tenant_id' => $tenant->getKey(),
                'provider' => $provider,
                'event_id' => $notification->eventId,
                'reference' => $notification->reference,
                'payload_hash' => hash('sha256', $payload),
            ]));
        } catch (UniqueConstraintViolationException) {
            return ApiResponse::success(['received' => true, 'duplicate' => true], 'Event already received.');
        }

        ProcessPaymentWebhookJob::dispatch($event->getKey());

        return ApiResponse::success(['received' => true], 'Event received.');
    }
}
