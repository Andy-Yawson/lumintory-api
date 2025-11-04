<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\Auth;

class StockReport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return Product::where('tenant_id', Auth::user()->tenant_id)
            ->orderBy('quantity')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Product',
            'Size',
            'Current Stock',
            'Low Stock Alert?',
            'Unit Price (GHS)',
            'Variations'
        ];
    }

    public function map($product): array
    {
        $threshold = $product->tenant?->settings['low_stock_threshold'] ?? 10;
        $lowStock = $product->quantity < $threshold ? 'YES' : 'NO';

        return [
            $product->name,
            $product->size,
            $product->quantity,
            $lowStock,
            $product->unit_price,
            collect($product->variations)->pluck('color')->join(', '),
        ];
    }
}
