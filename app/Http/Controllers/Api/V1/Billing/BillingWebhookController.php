<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\BillingService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Platform billing webhook', weight: 90)]
class BillingWebhookController extends Controller
{
    /**
     * Paystack tells us a platform subscription charge succeeded. Authenticated by signature; the
     * charge is then re-checked with Paystack, so the body is never trusted for amounts or status.
     */
    public function __invoke(Request $request, BillingGateway $gateway, BillingService $billing): JsonResponse
    {
        if (! $gateway->validSignature($request->getContent(), (string) $request->header('x-paystack-signature'))) {
            throw new BaseApiException(ApiErrorCodeEnum::InvalidWebhookSignature, 'Invalid webhook signature.');
        }

        $reference = $request->input('event') === 'charge.success' ? $request->input('data.reference') : null;
        $invoice = is_string($reference) && str_starts_with($reference, 'bill_')
            ? SubscriptionInvoice::withoutTenantScope()->where('reference', $reference)->first()
            : null;

        if ($invoice !== null) {
            try {
                $billing->confirm($invoice);
            } catch (PaymentException) {
                return ApiResponse::error(ApiErrorCodeEnum::PaymentProviderError, 'Could not confirm the payment yet.', 502); // Paystack retries
            }
        }

        return ApiResponse::success(['received' => true]);
    }
}
