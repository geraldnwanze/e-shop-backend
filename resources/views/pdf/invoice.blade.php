<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $order->order_number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 24px; margin: 0; letter-spacing: 1px; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .items th { text-align: left; font-size: 10px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 8px 6px; }
    .items td { padding: 8px 6px; border-bottom: 1px solid #f3f4f6; }
    .totals td { padding: 4px 6px; }
    .grand td { font-size: 14px; font-weight: bold; border-top: 2px solid #1f2937; padding-top: 8px; }
    .items th.right, .items td.right, .totals td.right, td.right { text-align: right; }
    .box { margin-top: 24px; }
    .label { font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
</style>
</head>
<body>
@php
    $address = fn (?array $a) => collect([
        trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
        $a['line1'] ?? null, $a['line2'] ?? null, $a['city'] ?? null,
        trim(($a['state'] ?? '').' '.($a['postal_code'] ?? '')), $a['country'] ?? null,
    ])->map(fn ($l) => is_string($l) ? trim($l) : '')->filter();
@endphp
<table class="head">
    <tr>
        <td>
            <strong style="font-size:16px">{{ $storeName }}</strong><br>
            @foreach ($contact as $line)<span class="muted">{{ $line }}</span><br>@endforeach
        </td>
        <td class="right">
            <h1>INVOICE</h1>
            <span class="muted">Order</span> {{ $order->order_number }}<br>
            <span class="muted">Date</span> {{ $issuedAt?->format('j M Y') }}
        </td>
    </tr>
</table>

<table class="box">
    <tr>
        <td style="width:50%; vertical-align: top">
            <div class="label">Billed to</div>
            <strong>{{ $order->customer_name }}</strong><br>
            {{ $order->customer_email }}<br>
            @foreach ($address($order->billing_address ?? $order->shipping_address)->skip(1) as $line){{ $line }}<br>@endforeach
        </td>
        <td style="width:50%; vertical-align: top">
            @if ($order->channel === 'pos')
                <div class="label">Sold</div>
                In store
            @else
                <div class="label">Shipped to</div>
                @foreach ($address($order->shipping_address) as $line){{ $line }}<br>@endforeach
                @if ($order->shipping_method)<span class="muted">{{ $order->shipping_method }}</span>@endif
            @endif
        </td>
    </tr>
</table>

<table class="items box">
    <thead><tr><th>Item</th><th class="right">Unit price</th><th class="right">Qty</th><th class="right">Total</th></tr></thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $item->name }}@if ($item->variant_name) <span class="muted">({{ $item->variant_name }})</span>@endif @if ($item->sku)<br><span class="muted">SKU {{ $item->sku }}</span>@endif</td>
            <td class="right">{{ $money($item->unit_price) }}</td>
            <td class="right">{{ $item->quantity }}</td>
            <td class="right">{{ $money($item->total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="width:46%; margin-left:54%; margin-top:12px">
    <tr><td>Subtotal</td><td class="right">{{ $money($order->subtotal) }}</td></tr>
    @if ($order->discount_total > 0)<tr><td>Discount @if ($order->discount_code)({{ $order->discount_code }})@endif</td><td class="right">−{{ $money($order->discount_total) }}</td></tr>@endif
    @if ($order->channel !== 'pos')<tr><td>Shipping</td><td class="right">{{ $order->shipping_total > 0 ? $money($order->shipping_total) : 'Free' }}</td></tr>@endif
    @if ($order->tax_total > 0)<tr><td>Tax</td><td class="right">{{ $money($order->tax_total) }}</td></tr>@endif
    <tr class="grand"><td>Total</td><td class="right">{{ $money($order->total) }}</td></tr>
    @if ($refunded > 0)<tr><td class="muted">Refunded</td><td class="right muted">−{{ $money($refunded) }}</td></tr>@endif
</table>

<div class="box muted">
    Paid @if ($paidWith)with {{ $paidWith }}@endif @if ($reference)· Reference {{ $reference }}@endif
</div>
</body>
</html>
