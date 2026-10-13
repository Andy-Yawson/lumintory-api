<?php

namespace App\Exports;

use App\Models\Sale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Best sellers by units, per product and variation (all time, current workspace). */
class TopProductsReport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private int $limit = 20)
    {
    }

    public function collection(): Collection
    {
        return Sale::selectRaw('product_id, variation_id, SUM(quantity) as total_sold, SUM(total_amount) as revenue, COUNT(*) as orders')
            ->with(['product:id,name,sku', 'variation:id,name'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->groupBy('product_id', 'variation_id')
            ->orderByDesc('total_sold')
            ->limit($this->limit)
            ->get();
    }

    public function headings(): array
    {
        $currency = Auth::user()->tenant->settings['currency'] ?? 'GHS';

        return ['Product', 'Variation', 'SKU', 'Units sold', 'Orders', "Revenue ({$currency})"];
    }

    public function map($row): array
    {
        return [
            $row->product?->name ?? 'Deleted product',
            $row->variation?->name ?? '',
            $row->product?->sku ?? '',
            (float) $row->total_sold,
            (int) $row->orders,
            round((float) $row->revenue, 2),
        ];
    }
}
