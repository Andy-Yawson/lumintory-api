@php
    $settings = $sale->tenant->settings ?? [];
    $symbol = $settings['currency_symbol'] ?? ($settings['currency'] ?? 'GHS');
    $money = fn ($n) => $symbol . ' ' . number_format((float) $n, 2);
    // 1.00 -> 1, 1.50 -> 1.5 (stock can be fractional, e.g. half a yard)
    $qty = rtrim(rtrim(number_format((float) $sale->quantity, 3, '.', ''), '0'), '.');
    $gross = (float) $sale->quantity * (float) $sale->unit_price;
    $discount = (float) ($sale->discount ?? 0);
    $contact = $sale->tenant->users()->where('role', 'Administrator')->value('email');
@endphp
<!DOCTYPE html>
<html>
<head>
    <title>Receipt #{{ $sale->id }}</title>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #24312b; margin: 0; }
        .header { text-align: center; padding-bottom: 12px; border-bottom: 1px solid #24312b; }
        .header h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #738078; }
        .meta { width: 100%; margin: 12px 0; }
        .meta td { padding: 2px 0; vertical-align: top; }
        .meta td.r { text-align: right; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th { text-align: left; font-size: 10px; text-transform: uppercase; color: #738078; border-bottom: 1px solid #cfd6cb; padding: 6px 4px; }
        table.items td { padding: 8px 4px; border-bottom: 1px solid #e0e4dd; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        table.totals { width: 55%; margin-left: 45%; border-collapse: collapse; margin-top: 10px; }
        table.totals td { padding: 3px 4px; }
        table.totals tr.grand td { font-size: 14px; font-weight: bold; border-top: 1px solid #24312b; padding-top: 6px; }
        .note { margin-top: 16px; }
        .footer { text-align: center; margin-top: 22px; font-size: 9px; color: #738078; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $sale->tenant->name }}</h1>
        <div class="muted">Receipt</div>
    </div>

    <table class="meta">
        <tr>
            <td><strong>Receipt #{{ $sale->id }}</strong><br>
                <span class="muted">{{ $sale->sale_date->format('j M Y') }} · {{ $sale->created_at->format('g:i A') }}</span></td>
            <td class="r">{{ $sale->customer?->name ?? 'Walk-in customer' }}@if($sale->customer?->phone)<br><span class="muted">{{ $sale->customer->phone }}</span>@endif<br>
                <span class="muted">Paid by {{ $sale->payment_method ?? 'Cash' }}</span></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $sale->product?->name ?? 'Item' }}@if($sale->variation)<br><span class="muted">{{ $sale->variation->name }}</span>@endif</td>
                <td class="num">{{ $qty }}</td>
                <td class="num">{{ $money($sale->unit_price) }}</td>
                <td class="num">{{ $money($gross) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        @if($discount > 0)
            <tr><td class="muted">Subtotal</td><td class="num">{{ $money($gross) }}</td></tr>
            <tr><td class="muted">Discount</td><td class="num">− {{ $money($discount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $money($sale->total_amount) }}</td></tr>
    </table>

    <div class="note">{{ $sale->notes ?: 'Thank you for your purchase!' }}</div>

    <div class="footer">
        @if($contact)Questions? {{ $contact }}<br>@endif
        Powered by Zinnvy Inventory
    </div>
</body>
</html>
