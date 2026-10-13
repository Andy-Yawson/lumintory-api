@php
    $settings = $sale->tenant->settings ?? [];
    $symbol = $settings['currency_symbol'] ?? ($settings['currency'] ?? 'GHS');
    $money = fn ($n) => $symbol . ' ' . number_format((float) $n, 2);
    // 1.00 -> 1, 1.50 -> 1.5 (stock can be fractional, e.g. half a yard)
    $qty = rtrim(rtrim(number_format((float) $sale->quantity, 3, '.', ''), '0'), '.');
    $gross = (float) $sale->quantity * (float) $sale->unit_price;
    $discount = (float) ($sale->discount ?? 0);
    $contact = $sale->tenant->users()->where('role', 'Administrator')->value('email');
    $initial = mb_strtoupper(mb_substr(trim($sale->tenant->name), 0, 1));
@endphp
<!DOCTYPE html>
<html>
<head>
    <title>Receipt #{{ $sale->id }}</title>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #24312b; margin: 0; background: #ffffff; }
        .band { background: #1f5a45; color: #f7f6ef; padding: 26px 30px 22px; }
        .band table { width: 100%; }
        .mark { width: 38px; height: 38px; border-radius: 10px; background: #e4eee5; color: #1f5a45; text-align: center;
                font-size: 18px; font-weight: bold; line-height: 1; padding-top: 9px; height: 29px; }
        .shop { font-size: 17px; font-weight: bold; padding-left: 10px; }
        .tag { color: #c5dac8; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; padding-left: 10px; }
        .band .r { text-align: right; vertical-align: top; }
        .band .no { font-size: 13px; font-weight: bold; }
        .band .muted-light { color: #c5dac8; }
        .wrap { padding: 20px 30px 26px; }
        .card { background: #f6f5f0; border: 1px solid #e0e4dd; border-radius: 10px; padding: 12px 14px; }
        .card table { width: 100%; }
        .label { color: #738078; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; }
        .val { font-size: 11px; font-weight: bold; margin-top: 2px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.items th { text-align: left; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #738078;
                         border-bottom: 1.5px solid #1f5a45; padding: 0 4px 7px; }
        table.items td { padding: 11px 4px; border-bottom: 1px solid #e0e4dd; vertical-align: top; }
        table.items .num { text-align: right; white-space: nowrap; }
        .item { font-weight: bold; }
        .sub { color: #738078; font-size: 9.5px; }
        table.totals { width: 58%; margin-left: 42%; border-collapse: collapse; margin-top: 12px; }
        table.totals td { padding: 4px; }
        table.totals .num { text-align: right; white-space: nowrap; }
        .grand td { background: #e4eee5; color: #1f5a45; font-size: 14px; font-weight: bold; padding: 9px 10px !important; }
        .paid { display: inline-block; margin-top: 16px; background: #e5f0e6; color: #3d8a62; border-radius: 20px; padding: 4px 11px;
                font-size: 9px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; }
        .note { margin-top: 14px; color: #24312b; font-style: italic; }
        .footer { margin-top: 26px; padding-top: 12px; border-top: 1px dashed #cfd6cb; text-align: center; color: #738078; font-size: 8.5px; line-height: 14px; }
        .footer b { color: #1f5a45; }
    </style>
</head>
<body>
    <div class="band">
        <table><tr>
            <td style="width:38px"><div class="mark">{{ $initial }}</div></td>
            <td><div class="shop">{{ $sale->tenant->name }}</div><div class="tag">Sales receipt</div></td>
            <td class="r"><div class="no">#{{ str_pad($sale->id, 5, '0', STR_PAD_LEFT) }}</div>
                <div class="muted-light">{{ $sale->sale_date->format('j M Y') }} · {{ $sale->created_at->format('g:i A') }}</div></td>
        </tr></table>
    </div>

    <div class="wrap">
        <div class="card"><table><tr>
            <td><div class="label">Customer</div><div class="val">{{ $sale->customer?->name ?? 'Walk-in customer' }}</div>
                @if($sale->customer?->phone)<div class="sub">{{ $sale->customer->phone }}</div>@endif</td>
            <td style="text-align:right"><div class="label">Payment</div><div class="val">{{ $sale->payment_method ?? 'Cash' }}</div></td>
        </tr></table></div>

        <table class="items">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
            <tbody><tr>
                <td><div class="item">{{ $sale->product?->name ?? 'Item' }}</div>@if($sale->variation)<div class="sub">{{ $sale->variation->name }}</div>@endif</td>
                <td class="num">{{ $qty }}</td>
                <td class="num">{{ $money($sale->unit_price) }}</td>
                <td class="num">{{ $money($gross) }}</td>
            </tr></tbody>
        </table>

        <table class="totals">
            <tr><td class="sub">Subtotal</td><td class="num">{{ $money($gross) }}</td></tr>
            @if($discount > 0)<tr><td class="sub">Discount</td><td class="num">− {{ $money($discount) }}</td></tr>@endif
            <tr class="grand"><td>Total paid</td><td class="num">{{ $money($sale->total_amount) }}</td></tr>
        </table>

        <div class="paid">✓ Paid</div>
        <div class="note">{{ $sale->notes ?: 'Thank you for your purchase!' }}</div>

        <div class="footer">
            @if($contact)Questions about this receipt? {{ $contact }}<br>@endif
            Powered by <b>Zinnvy Inventory</b>
        </div>
    </div>
</body>
</html>
