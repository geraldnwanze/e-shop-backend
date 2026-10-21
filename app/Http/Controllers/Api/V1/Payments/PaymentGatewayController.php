<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\UpdateGatewayRequest;
use App\Models\PaymentGateway;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Merchant-managed provider credentials. Secrets are write-only: responses expose
 * only presence flags and the last four characters of the key.
 */
#[Group('Merchant: Payment gateways', weight: 26)]
class PaymentGatewayController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $this->authorize('viewAny', PaymentGateway::class);

        $gateways = PaymentGateway::query()->get()->keyBy(fn (PaymentGateway $g) => $g->provider->value);

        return ApiResponse::success(collect(PaymentProviderType::cases())->map(
            fn (PaymentProviderType $type) => $this->present($type, $gateways->get($type->value), $context),
        )->values());
    }

    public function update(UpdateGatewayRequest $request, PaymentProviderType $provider, TenantContext $context): JsonResponse
    {
        $this->authorize('create', PaymentGateway::class);

        $gateway = DB::transaction(function () use ($request, $provider): PaymentGateway {
            $gateway = PaymentGateway::query()->firstOrNew(['provider' => $provider->value]);
            $gateway->provider = $provider;
            $gateway->fill($request->validated());
            $gateway->save();

            if ($gateway->is_default) {
                PaymentGateway::query()->whereKeyNot($gateway->getKey())->update(['is_default' => false]);
            }

            return $gateway->refresh();
        });

        return ApiResponse::success($this->present($provider, $gateway, $context), 'Payment gateway saved.');
    }

    public function destroy(PaymentProviderType $provider): JsonResponse
    {
        $this->authorize('create', PaymentGateway::class);

        PaymentGateway::query()->where('provider', $provider->value)->delete();

        return ApiResponse::noContent('Payment gateway removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentProviderType $type, ?PaymentGateway $gateway, TenantContext $context): array
    {
        return [
            'provider' => $type,
            'configured' => $gateway !== null,
            'isActive' => $gateway?->is_active ?? false,
            'isDefault' => $gateway?->is_default ?? false,
            'publicKey' => $gateway?->public_key,
            'secretKeyHint' => $gateway ? '••••'.substr($gateway->secret_key, -4) : null,
            'hasWebhookSecret' => filled($gateway?->webhook_secret),
            'webhookUrl' => route('api.v1.webhooks.payments', ['provider' => $type->value, 'tenant' => $context->requireTenant()->id]),
        ];
    }
}
