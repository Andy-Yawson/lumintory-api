<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\Auth;
use App\Models\Sale;

class TopProductsReport implements FromCollection, WithHeadings
{
    public function collection()
    {
        return Sale::selectRaw('product_id, color, SUM(quantity) as total_sold, SUM(total_amount) as revenue')
            ->with('product')
            ->where('tenant_id', Auth::user()->tenant_id)
            ->groupBy('product_id', 'color')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get();
    }

    public function headings(): array
    {
        return ['Product', 'Color', 'Units Sold', 'Revenue (GHS)'];
    }

    public function map($row): array
    {
        return [
            $row->product->name,
            $row->color,
            $row->total_sold,
            $row->revenue,
        ];
    }
}
