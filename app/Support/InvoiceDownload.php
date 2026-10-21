<?php

namespace App\Support;

use App\Models\Order;
use App\Services\Orders\InvoiceService;
use Illuminate\Http\Response;

final class InvoiceDownload
{
    public static function response(InvoiceService $invoices, Order $order): Response
    {
        $pdf = $invoices->render($order);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoices->filename($order).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
