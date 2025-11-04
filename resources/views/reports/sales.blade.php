<!DOCTYPE html>
<html>
<head>
    <title>Sales Report</title>
    <style>
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Sales Report</h1>
    <table>
        <thead>
            <tr>
                <th>Date</th><th>Product</th><th>Color</th><th>Qty</th><th>Price</th><th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sales as $sale)
            <tr>
                <td>{{ $sale->sale_date }}</td>
                <td>{{ $sale->product->name }}</td>
                <td>{{ $sale->color }}</td>
                <td>{{ $sale->quantity }}</td>
                <td>{{ $sale->unit_price }}</td>
                <td>{{ $sale->total_amount }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
