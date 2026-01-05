<?php

namespace App\Exports;

use App\Models\ReturnItem;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Auth;

class ReturnsReport implements FromCollection, WithHeadings, WithMapping
{
    protected $startDate;
    protected $endDate;

    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function collection()
    {
        return ReturnItem::with(['product', 'sale.customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereBetween('return_date', [$this->startDate, $this->endDate])
            ->get();
    }

    public function headings(): array
    {
        return [
            'Return Date',
            'Product',
            'Color',
            'Quantity',
            'Refund (GHS)',
            'Reason',
            'Customer',
            'Sale Date',
            'Refund Method'
        ];
    }

    public function map($return): array
    {
        return [
            $return->return_date,
            $return->product->name,
            $return->color,
            $return->quantity,
            $return->refund_amount,
            $return->reason,
            $return->sale?->customer?->name ?? 'N/A',
            $return->sale?->sale_date,
            $return->refund_method
        ];
    }
}
