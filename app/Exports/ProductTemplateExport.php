<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'name',
            'sku',
            'description',
            'category',
            'quantity',
            'unit_price',
            'size',
            'variations',
        ];
    }

    public function array(): array
    {
        return [
            [
                'Bread Crust',
                'BREAD-001',
                'Sample bread product',
                'Bakery',
                50,
                100,
                null,
                'Large:110:20 | Medium:86:30 (Format: Name:Price:Stock | Name:Price:Stock)',
            ],
        ];
    }
}
