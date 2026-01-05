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
            'variations', // JSON: e.g. [{"type":"Large","price":110},{"type":"Medium","price":86}]
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
                '[{"type":"Large","price":110},{"type":"Medium","price":86}]',
            ],
        ];
    }
}
