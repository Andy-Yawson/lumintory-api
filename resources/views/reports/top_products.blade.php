@php
    $tenant = auth()->user()->tenant;
    $symbol = $tenant->settings['currency_symbol'] ?? ($tenant->settings['currency'] ?? 'GHS');
    $money = fn ($n) => $symbol . ' ' . number_format((float) $n, 2);
    $units = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Top products · {{ now()->format('M d, Y') }}</title>
    <style>
        @page { margin: 1cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #24312b; }
        .header { border-bottom: 2px solid #1f5a45; padding-bottom: 8px; margin-bottom: 16px; }
        .header table { width: 100%; }
        .title { font-size: 18pt; font-weight: bold; color: #1f5a45; margin: 0; }
        .muted { color: #738078; font-size: 8.5pt; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #e4eee5; color: #1f5a45; text-align: left; font-size: 8pt; text-transform: uppercase; padding: 7px 6px; }
        table.data td { padding: 7px 6px; border-bottom: 1px solid #e0e4dd; }
        table.data .num { text-align: right; white-space: nowrap; }
        .rank { color: #738078; width: 22px; }
        .footer { margin-top: 18px; text-align: center; color: #738078; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="header">
        <table><tr>
            <td><p class="title">Top products</p><span class="muted">Best sellers by units, all time</span></td>
            <td style="text-align:right"><strong>{{ $tenant->name }}</strong><br><span class="muted">Generated {{ now()->format('j M Y, g:i A') }}</span></td>
        </tr></table>
    </div>

    @if($products->isEmpty())
        <p class="muted">No sales recorded yet.</p>
    @else
        <table class="data">
            <thead><tr><th class="rank">#</th><th>Product</th><th class="num">Units sold</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead>
            <tbody>
            @foreach($products as $i => $row)
                <tr>
                    <td class="rank">{{ $i + 1 }}</td>
                    <td>{{ $row->product?->name ?? 'Deleted product' }}@if($row->variation) <span class="muted">· {{ $row->variation->name }}</span>@endif</td>
                    <td class="num">{{ $units($row->total_sold) }}</td>
                    <td class="num">{{ (int) $row->orders }}</td>
                    <td class="num">{{ $money($row->revenue) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Zinnvy Inventory</div>
</body>
</html>
