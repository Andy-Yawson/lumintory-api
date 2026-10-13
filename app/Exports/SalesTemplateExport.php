<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['date', 'product', 'variation', 'quantity', 'unit_price', 'discount', 'payment_method', 'customer', 'phone', 'notes'];
    }

    public function array(): array
    {
        return [
            ['13/10/2026', 'Bread Crust', 'Large', 2, 110, 0, 'Mobile Money', 'Ama Mensah', '0241234567', ''],
            ['13/10/2026', 'Milo 400g', '', 1, 45, 5, 'Cash', '', '', 'Walk-in'],
        ];
    }
}
