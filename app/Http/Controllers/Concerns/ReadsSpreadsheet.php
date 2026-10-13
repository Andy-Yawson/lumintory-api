<?php

namespace App\Http\Controllers\Concerns;

use Maatwebsite\Excel\Facades\Excel;

trait ReadsSpreadsheet
{
    /** First sheet → [headers, non-blank data rows]. Blank header cells become "Column N". */
    private function readSpreadsheet($file): array
    {
        $sheet = Excel::toArray(null, $file)[0] ?? [];
        $headerRow = array_shift($sheet) ?? [];

        $headers = [];
        foreach ($headerRow as $i => $h) {
            $h = trim((string) $h);
            $headers[] = $h !== '' ? $h : 'Column ' . ($i + 1);
        }

        $rows = array_values(array_filter($sheet, fn ($r) => collect($r)->contains(fn ($v) => $v !== null && trim((string) $v) !== '')));

        return [$headers, $rows];
    }
}
