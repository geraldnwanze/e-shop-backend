<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\InitializePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Storefront: Checkout & payments', weight: 12)]
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Idempotent (Idempotency-Key). Guests prove ownership with the order UUID
     * plus the order's email; signed-in customers must own the order.
     */
    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $order = $this->ownedOrder($request, $request->validated('order_id'), $request->validated('email'));

        $result = $this->payments->initialize($order, $request->enum('provider', PaymentProviderType::class));

        return ApiResponse::success([
            'requiresPayment' => $result['requiresPayment'],
            'payment' => $result['payment'] ? PaymentResource::make($result['payment']) : null,
            'orderNumber' => $order->order_number,
        ], $result['requiresPayment'] ? 'Payment initialized.' : 'No payment required.');
    }

    /**
     * Called by the storefront after the provider redirects the customer back.
     * The redirect is NOT trusted: the provider is asked directly, server-side.
     */
    public function verify(string $reference): JsonResponse
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();
        $payment = $this->payments->verifyAndConfirm($payment);
        $order = Order::query()->findOrFail($payment->order_id);

        return ApiResponse::success([
            'reference' => $payment->reference,
            'status' => $payment->status,
            'orderNumber' => $order->order_number,
            'orderStatus' => $order->status,
            'paymentStatus' => $order->payment_status,
        ]);
    }

    private function ownedOrder(Request $request, string $uuid, ?string $email): Order
    {
        $order = Order::query()->where('id', $uuid)->firstOrFail();
        $actor = $request->user('sanctum');

        $owns = $order->placed_authenticated
            ? $actor instanceof Customer && $actor->getKey() === $order->customer_id
            : $email !== null && hash_equals($order->customer_email, $email);

        abort_unless($owns, 404);

        return $order;
    }
}
