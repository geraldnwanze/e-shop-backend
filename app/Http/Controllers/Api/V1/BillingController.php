<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingSummary;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant: Billing', weight: 24)]
class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly BillingSummary $summary, private readonly TenantContext $context) {}

    /**
     * Current plan, trial or renewal state, the plans on offer with their prices, and recent invoices.
     */
    public function show(): JsonResponse
    {
        $this->authorize('manage-billing');

        return ApiResponse::success($this->summary->for($this->context->requireTenant()));
    }

    /**
     * Start paying for a plan. Returns the Paystack page to send the merchant to.
     */
    public function checkout(BillingCheckoutRequest $request): JsonResponse
    {
        $this->authorize('manage-billing');

        ['invoice' => $invoice, 'url' => $url] = $this->billing->checkout(
            $this->context->requireTenant(), $request->user(), Plan::query()->findOrFail($request->validated('plan_id')), (int) $request->validated('months'),
        );

        return ApiResponse::created(['reference' => $invoice->reference, 'authorizationUrl' => $url], 'Continue to payment.');
    }

    /**
     * Called when the merchant returns from Paystack: confirms the payment with Paystack itself.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->authorize('manage-billing');

        $invoice = SubscriptionInvoice::query()->where('reference', $request->validate(['reference' => ['required', 'string', 'max:64']])['reference'])->firstOrFail();
        $invoice = $this->billing->confirm($invoice);

        return ApiResponse::success(['status' => $invoice->status, 'billing' => $this->summary->for($this->context->requireTenant())]);
    }

    /**
     * Stop auto-renewing. The plan stays active until the paid period ends.
     */
    public function cancel(): JsonResponse
    {
        $this->authorize('manage-billing');

        $this->billing->cancelAutoRenew($this->context->requireTenant());

        return ApiResponse::success($this->summary->for($this->context->requireTenant()), 'Auto-renew turned off.');
    }

    public function resume(): JsonResponse
    {
        $this->authorize('manage-billing');

        $this->billing->resumeAutoRenew($this->context->requireTenant());

        return ApiResponse::success($this->summary->for($this->context->requireTenant()), 'Auto-renew turned on.');
    }
}
