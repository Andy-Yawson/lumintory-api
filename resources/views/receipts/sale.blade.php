<!DOCTYPE html>
<html>
<head>
    <title>Receipt #{{ $sale->id }}</title>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; margin: 40px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 20px; }
        .info { margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #333; padding: 12px; text-align: left; }
        th { background: #f0f0f0; }
        .total { font-weight: bold; font-size: 1.3em; text-align: right; }
        .footer { text-align: center; margin-top: 50px; font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $sale->tenant->name ?? 'Lumintory Business' }}</h1>
        <p>Official Receipt</p>
    </div>

    <div class="info">
        <p><strong>Receipt #:</strong> {{ $sale->id }}</p>
        <p><strong>Date:</strong> {{ $sale->sale_date->format('d F Y') }}</p>
        <p><strong>Time:</strong> {{ $sale->created_at->format('h:i A') }}</p>
        @if($sale->customer)
            <p><strong>Customer:</strong> {{ $sale->customer->name }} | {{ $sale->customer->phone ?? 'N/A' }}</p>
        @else
            <p><strong>Customer:</strong> Walk-in</p>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Color</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $sale->product->name }}</td>
                <td>{{ $sale->color ?? 'Standard' }}</td>
                <td>{{ $sale->quantity }}</td>
                <td>{{ $sale->tenant->settings['currency'] ?? 'GHS' }} {{ number_format($sale->unit_price, 2) }}</td>
                <td class="total">{{ number_format($sale->total_amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="info">
        <p><strong>Notes:</strong> {{ $sale->notes ?? 'Thank you for your purchase!' }}</p>
    </div>

    <div class="footer">
        <p>Powered by <strong>Lumintory</strong> • Inventory Management System</p>
        <p>Contact: {{ $sale->tenant->users()->first()?->email ?? 'support@lumintory.com' }}</p>
    </div>
</body>
</html>
