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

    /**
     * Fetching all columns needed for a "Pro" level analysis
     */
    public function collection()
    {
        return Sale::with(['product', 'customer', 'variation', 'user'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->whereBetween('sale_date', [$this->startDate, $this->endDate])
            ->orderBy('sale_date', 'desc')
            ->get();
    }

    /**
     * Professional Headings
     */
    public function headings(): array
    {
        return [
            'Receipt #',
            'Date/Time',
            'Product Name',
            'Variation/Color',
            'Qty',
            'Unit Price (GHS)',
            'Subtotal',
            'Discount',
            'Tax',
            'Total Amount',
            'Payment Method',
            'Customer',
            'Sold By',
            'Status',
            'Notes'
        ];
    }

    /**
     * Detailed Mapping including variation check and subtotal logic
     */
    public function map($sale): array
    {
        // Handle variation name or default to N/A
        $variationName = $sale->variation ? $sale->variation->name : ($sale->color ?? 'N/A');

        // Calculate subtotal before discount (if your DB stores final total)
        $subtotal = $sale->total_amount + $sale->discount;

        return [
            $sale->invoice_number ?? $sale->id, // Use an invoice number if exists
            $sale->sale_date->format('Y-m-d H:i'),
            $sale->product->name,
            $variationName,
            $sale->quantity,
            number_format($sale->unit_price, 2),
            number_format($subtotal, 2),
            number_format($sale->discount, 2),
            number_format($sale->tax_amount ?? 0, 2),
            number_format($sale->total_amount, 2),
            ucfirst($sale->payment_method),
            $sale->customer?->name ?? 'Walk-in Customer',
            $sale->user?->name ?? 'System',
            $sale->status ?? 'Completed',
            $sale->notes,
        ];
    }

    /**
     * Style the header row for Excel
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4F46E5'] // Indigo-600
                ]
            ],
        ];
    }
}