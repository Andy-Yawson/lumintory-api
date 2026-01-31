<?php

namespace App\Exports;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Auth;

class SalesReport implements FromCollection, WithHeadings, WithMapping
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
        return Sale::with(['product', 'customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereBetween('sale_date', [$this->startDate, $this->endDate])
            ->get();
    }

    public function headings(): array
    {
        return [
            'Date',
            'Product',
            'Color',
            'Quantity',
            'Unit Price',
            'Total',
            'Discount',
            'Payment Method',
            'Customer',
            'Notes'
        ];
    }

    public function map($sale): array
    {
        return [
            $sale->sale_date,
            $sale->product->name,
            $sale->color,
            $sale->quantity,
            $sale->unit_price,
            $sale->total_amount,
            $sale->discount,
            $sale->payment_method,
            $sale->customer?->name ?? 'Walk-in',
            $sale->notes,
        ];
    }
}
