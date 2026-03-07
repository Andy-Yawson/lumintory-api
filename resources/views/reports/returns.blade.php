<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Returns Report - {{ $summary['period'] ?? now()->format('M Y') }}</title>
    <style>
        @page {
            margin: 1cm;
        }

        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10pt;
            color: #333;
            line-height: 1.4;
        }

        .header {
            border-bottom: 2px solid #e11d48;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .header table {
            width: 100%;
        }

        .report-title {
            font-size: 20pt;
            font-weight: bold;
            color: #9f1239;
            margin: 0;
        }

        .company-info {
            text-align: right;
            font-size: 9pt;
            color: #666;
        }

        /* Summary Cards */
        .summary-container {
            margin-bottom: 30px;
            width: 100%;
        }

        .summary-card {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            padding: 15px;
            width: 30%;
            display: inline-block;
            vertical-align: top;
            margin-right: 2%;
            border-radius: 8px;
        }

        .summary-label {
            font-size: 8pt;
            color: #be123c;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 14pt;
            font-weight: bold;
            color: #9f1239;
        }

        /* Table Styles */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #e11d48;
            color: white;
            text-align: left;
            padding: 10px 5px;
            font-size: 8pt;
            text-transform: uppercase;
        }

        td {
            padding: 8px 5px;
            border-bottom: 1px solid #fecdd3;
            font-size: 9pt;
        }

        tr:nth-child(even) {
            background-color: #fffafb;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-family: 'Courier', monospace;
            font-weight: bold;
        }

        .reason-text {
            font-size: 8pt;
            color: #64748b;
            font-style: italic;
        }

        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            font-size: 8pt;
            text-align: center;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 5px;
        }
    </style>


</head>

<body>

    <div class="header">
        <table>
            <tr>
                <td>
                    <h1 class="report-title">Returns & Refunds</h1>
                    <p style="margin: 5px 0 0 0; color: #64748b;">Period: {{ $summary['period'] ?? 'Full Audit' }}</p>
                </td>
                <td class="company-info">
                    <strong>Total Returns:</strong> {{ count($returns) }}<br>
                    <strong>Date Generated:</strong> {{ now()->format('M d, Y H:i') }}<br>
                </td>
            </tr>
        </table>
    </div>

    <div class="summary-container">
        <div class="summary-card">
            <div class="summary-label">Total Refunded</div>
            <div class="summary-value">GHS {{ number_format($returns->sum('refund_amount'), 2) }}</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Items Returned</div>
            <div class="summary-value">{{ number_format($returns->sum('quantity')) }}</div>
        </div>
        <div class="summary-card" style="margin-right: 0;">
            <div class="summary-label">Avg. Refund Value</div>
            <div class="summary-value">
                GHS
                {{ count($returns) > 0 ? number_format($returns->sum('refund_amount') / count($returns), 2) : '0.00' }}
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Product & Variation</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Refund Amt</th>
                <th>Reason</th>
                <th>Customer</th>
                <th>Method</th>
            </tr>
        </thead>
        <tbody>
            @foreach($returns as $r)
                <tr>
                    <td>{{ $r->return_date->format('d/m/y') }}</td>
                    <td>
                        <strong>{{ $r->product->name ?? 'Deleted Product' }}</strong><br>
                        <span style="font-size: 8pt; color: #64748b;">
                            {{ $r->variation?->name ?? ($r->color ?? 'Standard') }}
                        </span>
                    </td>
                    <td class="text-right">{{ $r->quantity }}</td>
                    <td class="text-right amount">{{ number_format($r->refund_amount, 2) }}</td>
                    <td class="reason-text">{{ $r->reason ?? 'No reason provided' }}</td>
                    <td>{{ $r->sale?->customer?->name ?? 'Walk-in' }}</td>
                    <td><small>{{ ucfirst($r->refund_method ?? 'Cash') }}</small></td>
                </tr>
            @endforeach
        </tbody>
    </table>

</body>

</html>