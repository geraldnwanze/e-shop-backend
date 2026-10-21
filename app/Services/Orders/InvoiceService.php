<?php

namespace App\Services\Orders;

use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Exceptions\BaseApiException;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Support\Notifications\StoreMailContext;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders an order's invoice as a PDF. Invoices exist once money has changed hands, so they are
 * not available for unpaid or failed orders. Call inside the order's tenant context.
 */
class InvoiceService
{
    public function isAvailable(Order $order): bool
    {
        return in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true);
    }

    /**
     * @throws BaseApiException when the order has not been paid
     */
    public function render(Order $order): string
    {
        if (! $this->isAvailable($order)) {
            throw BaseApiException::conflict('An invoice is only available once the order has been paid.');
        }

        $order->loadMissing(['items', 'payments']);
        $paid = $order->payments->filter(fn ($p) => in_array($p->status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true));
        $contact = StoreSetting::query()->first()?->settings['contact'] ?? [];

        return Pdf::loadView('pdf.invoice', [
            'order' => $order,
            'storeName' => Store::query()->value('name') ?? app(TenantContext::class)->requireTenant()->name,
            'contact' => array_filter([$contact['address'] ?? null, $contact['email'] ?? null, $contact['phone'] ?? null]),
            'issuedAt' => $paid->max('paid_at') ?? $order->placed_at,
            'paidWith' => $order->channel === 'pos'
                ? (PosPaymentMethod::tryFrom((string) $order->payment_method)?->label() ?? '')
                : $paid->map(fn ($p) => ucfirst($p->provider->value))->unique()->implode(', '),
            'reference' => $paid->pluck('reference')->filter()->implode(', '),
            'refunded' => (int) $order->payments->sum('refunded_amount'),
            'money' => fn (int $minor) => StoreMailContext::money($minor, $order->currency),
        ])->setPaper('a4')->setOption(['enable_font_subsetting' => true])->output();
    }

    public function filename(Order $order): string
    {
        return 'Invoice-'.preg_replace('/[^A-Za-z0-9._-]/', '', $order->order_number).'.pdf';
    }
}
