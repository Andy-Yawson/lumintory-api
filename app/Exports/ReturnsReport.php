<?php

namespace App\Exports;

use App\Models\ReturnItem;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Auth;

class ReturnsReport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
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
        // Eager load product, sale (with customer), and the variation via your defined relationship
        return ReturnItem::with(['product', 'sale.customer', 'saleVariation'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereBetween('return_date', [$this->startDate, $this->endDate])
            ->orderBy('return_date', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Return Date',
            'Product',
            'Variation',
            'Qty Returned',
            'Refund Amount (GHS)',
            'Reason',
            'Original Sale Date',
            'Customer',
            'Refund Method'
        ];
    }

    public function map($return): array
    {
        // Logic check: Priority to saleVariation relation, then color column, then N/A
        $variationDisplay = 'N/A';
        if ($return->saleVariation) {
            $variationDisplay = $return->saleVariation->name;
        } elseif (!empty($return->color)) {
            $variationDisplay = $return->color;
        }

        return [
            $return->return_date->format('Y-m-d'),
            $return->product->name ?? 'Deleted Product',
            $variationDisplay,
            $return->quantity,
            number_format($return->refund_amount, 2),
            $return->reason ?? 'No reason provided',
            $return->sale?->sale_date ? $return->sale->sale_date->format('Y-m-d') : 'N/A',
            $return->sale?->customer?->name ?? 'Walk-in',
            ucfirst($return->refund_method ?? 'Cash'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E11D48'] // Rose-600
                ]
            ],
        ];
    }
}