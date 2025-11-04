<!DOCTYPE html>
<html>
<head><title>Returns Report</title></head>
<body>
    <h1>Returns Report</h1>
    <table border="1" cellpadding="8" cellspacing="0">
        <tr><th>Date</th><th>Product</th><th>Qty</th><th>Refund</th><th>Reason</th></tr>
        @foreach($returns as $r)
        <tr>
            <td>{{ $r->return_date }}</td>
            <td>{{ $r->product->name }}</td>
            <td>{{ $r->quantity }}</td>
            <td>{{ $r->refund_amount }}</td>
            <td>{{ $r->reason }}</td>
        </tr>
        @endforeach
    </table>
</body>
</html>
