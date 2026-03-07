<?php

namespace App\Exports;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Auth;

class SalesReport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        return Sale::with([
            'product',
            'customer:id,name',
            'variation:id,name'
        ])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereBetween('sale_date', [$this->startDate, $this->endDate])
            ->orderBy('sale_date', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Receipt #',
            'Date/Time',
            'Product Name',
            'Variation',
            'Qty',
            'Unit Price (GHS)',
            'Subtotal',
            'Discount',
            'Total Amount',
            'Payment Method',
            'Customer',
            'Notes'
        ];
    }

    public function map($sale): array
    {
        $subtotal = $sale->total_amount + ($sale->discount ?? 0);

        return [
            $sale->invoice_number ?? $sale->id,
            $sale->sale_date->format('Y-m-d H:i'),
            $sale->product->name ?? 'Unknown',
            $sale->variation?->name ?? 'N/A',
            $sale->quantity,
            number_format($sale->unit_price, 2),
            number_format($subtotal, 2),
            number_format($sale->discount ?? 0, 2),
            number_format($sale->total_amount, 2),
            ucfirst($sale->payment_method),
            $sale->customer?->name ?? 'Walk-in Customer',
            $sale->notes,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5']
                ]
            ],
        ];
    }
}