<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Auth;

class StockReport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function collection()
    {
        // Eager load variations and category for a comprehensive report
        return Product::with(['variations', 'category'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderBy('quantity', 'asc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Product Name',
            'Category',
            'SKU',
            'Current Stock',
            'Unit Price (Sell)',
            'Purchase Price (Cost)',
            'Inventory Value (Cost)',
            'Status'
        ];
    }

    public function map($product): array
    {
        $threshold = $product->min_stock_threshold ?? 10;
        $status = $product->quantity <= 0 ? 'Out of Stock' : ($product->quantity <= $threshold ? 'Low Stock' : 'Healthy');

        // Calculate valuation based on cost price if available
        $cost = $product->purchase_price ?? 0;
        $valuation = $product->quantity * $cost;

        return [
            $product->name,
            $product->category?->name ?? 'Uncategorized',
            $product->sku ?? 'N/A',
            (float) $product->quantity,
            number_format($product->unit_price, 2),
            number_format($cost, 2),
            number_format($valuation, 2),
            $status
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