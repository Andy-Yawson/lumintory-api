<!DOCTYPE html>
<html>
<head><title>Stock Report</title></head>
<body>
    <h1>Current Stock Balance</h1>
    <table border="1" cellpadding="8" cellspacing="0">
        <tr><th>Product</th><th>Stock</th><th>Low Alert?</th></tr>
        @foreach($products as $p)
        <tr style="background: {{ $p->quantity < 10 ? '#ffcccc' : '' }}">
            <td>{{ $p->name }}</td>
            <td>{{ $p->quantity }}</td>
            <td>{{ $p->quantity < 10 ? 'YES' : 'NO' }}</td>
        </tr>
        @endforeach
    </table>
</body>
</html>
