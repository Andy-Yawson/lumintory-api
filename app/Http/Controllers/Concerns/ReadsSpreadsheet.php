<?php

namespace App\Http\Controllers\Concerns;

use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

trait ReadsSpreadsheet
{
    /**
     * One sheet of a workbook → [headers, non-blank data rows, sheet info].
     * Blank header cells become "Column N".
     *
     * Workbooks often keep products, sales etc. on separate sheets, so the caller can pick one
     * (`$sheet`, 0-based). Without a pick we take the first non-empty sheet whose name matches
     * `$prefer` (e.g. /product|stock/), else the first non-empty sheet.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>, 2: array{sheet: int, sheets: array<int, array{index: int, name: string, rows: int}>}}
     */
    private function readSpreadsheet($file, ?int $sheet = null, ?string $prefer = null): array
    {
        $all = Excel::toArray(null, $file);

        $names = rescue(function () use ($file) {
            $path = $file->getRealPath();
            $reader = IOFactory::createReaderForFile($path);

            return method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [];
        }, [], report: false);

        $nonBlank = fn (array $r) => collect($r)->contains(fn ($v) => $v !== null && trim((string) $v) !== '');
        $sheets = [];
        foreach ($all as $i => $data) {
            $rows = count(array_filter($data, $nonBlank));
            $sheets[] = ['index' => $i, 'name' => $names[$i] ?? 'Sheet '.($i + 1), 'rows' => max(0, $rows - 1)];
        }

        if ($sheet === null || ! isset($all[$sheet])) {
            $filled = array_values(array_filter($sheets, fn ($s) => $s['rows'] > 0));
            $match = $prefer ? collect($filled)->first(fn ($s) => preg_match($prefer, strtolower($s['name']))) : null;
            $sheet = ($match ?? $filled[0] ?? ['index' => 0])['index'];
        }

        $data = $all[$sheet] ?? [];
        $headerRow = array_shift($data) ?? [];

        $headers = [];
        foreach ($headerRow as $i => $h) {
            $h = trim((string) $h);
            $headers[] = $h !== '' ? $h : 'Column '.($i + 1);
        }

        $rows = array_values(array_filter($data, $nonBlank));

        return [$headers, $rows, ['sheet' => $sheet, 'sheets' => $sheets]];
    }
}
