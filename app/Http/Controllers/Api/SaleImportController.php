<?php

namespace App\Http\Controllers\Api;

use App\Exports\SalesTemplateExport;
use App\Http\Controllers\Concerns\ReadsSpreadsheet;
use App\Http\Controllers\Controller;
use App\Services\PlanLimit;
use App\Services\SalesImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Guided import of past sales: upload → match columns → review (dry run) → import.
 * Mirrors the product import; the rules live in SalesImportService.
 */
class SaleImportController extends Controller
{
    use ReadsSpreadsheet;

    private const FILE = 'required|file|mimes:xlsx,xls,csv,txt|max:10240';

    public function template()
    {
        return Excel::download(new SalesTemplateExport, 'sales_import_template.xlsx');
    }

    public function preview(Request $request)
    {
        $this->authorizeAdmin();
        $request->validate(['file' => self::FILE, 'sheet' => 'nullable|integer|min:0']);
        [$headers, $rows, $book] = $this->readSpreadsheet($request->file('file'), $request->filled('sheet') ? $request->integer('sheet') : null, '/sale|order|transaction|receipt|invoice/');

        if (empty($headers)) {
            return response()->json(['message' => 'That file looks empty.'], 422);
        }

        return response()->json([
            'headers' => $headers,
            'sample' => array_slice($rows, 0, 5),
            'total_rows' => count($rows),
            'fields' => SalesImportService::fieldCatalogue(),
            'suggested_mapping' => (object) SalesImportService::suggestMapping($headers),
            'row_limit' => PlanLimit::getLimit(Auth::user()->tenant, 'excel_import_rows'),
        ] + $book);
    }

    public function dryRun(Request $request, SalesImportService $importer)
    {
        $this->authorizeAdmin();
        [$rows, $mapping, $opts] = $this->input($request);
        $tenant = Auth::user()->tenant;

        return response()->json($importer->plan($tenant->id, $rows, $mapping, $opts['missing'], $opts['stock'], PlanLimit::getLimit($tenant, 'products'))
            + ['row_limit' => PlanLimit::getLimit($tenant, 'excel_import_rows')]);
    }

    public function import(Request $request, SalesImportService $importer)
    {
        $this->authorizeAdmin();
        [$rows, $mapping, $opts] = $this->input($request);
        $tenant = Auth::user()->tenant;

        $rowLimit = PlanLimit::getLimit($tenant, 'excel_import_rows');
        if ($rowLimit !== null && count($rows) > $rowLimit) {
            return response()->json(['message' => "Your plan allows importing up to {$rowLimit} rows per file. This file has ".count($rows).'.'], 422);
        }

        $r = $importer->import(
            $tenant->id, $rows, $mapping, $opts['missing'], $opts['stock'],
            PlanLimit::getLimit($tenant, 'products'), PlanLimit::getLimit($tenant, 'customers'),
        );

        $parts = array_filter([
            $r['imported'] ? "{$r['imported']} sale".($r['imported'] === 1 ? '' : 's').' imported' : null,
            $r['new_products'] ? "{$r['new_products']} new product".($r['new_products'] === 1 ? '' : 's') : null,
            $r['new_customers'] ? "{$r['new_customers']} new customer".($r['new_customers'] === 1 ? '' : 's') : null,
            $r['failed'] ? "{$r['failed']} failed" : null,
        ]);

        return response()->json($r + ['message' => $parts ? ucfirst(implode(', ', $parts)).'.' : 'Nothing was imported.']);
    }

    /** Importing history rewrites reports, so it's an Administrator job. */
    private function authorizeAdmin(): void
    {
        abort_unless(Auth::user()->role === 'Administrator', 403, 'Only a workspace Administrator can import sales.');
    }

    private function input(Request $request): array
    {
        $request->validate([
            'file' => self::FILE,
            'mapping' => 'required|string',
            'missing' => 'nullable|in:skip,create',
            'stock' => 'nullable|in:keep,deduct',
            'sheet' => 'nullable|integer|min:0',
        ]);
        [, $rows] = $this->readSpreadsheet($request->file('file'), $request->filled('sheet') ? $request->integer('sheet') : null, '/sale|order|transaction|receipt|invoice/');
        $mapping = array_map('intval', array_filter((array) json_decode($request->input('mapping'), true), fn ($v) => $v !== null && $v !== ''));

        foreach (['product' => 'the product', 'quantity' => 'the quantity'] as $f => $label) {
            if (! isset($mapping[$f])) {
                abort(response()->json(['message' => "Choose which column holds {$label}."], 422));
            }
        }

        return [$rows, $mapping, ['missing' => $request->input('missing', 'skip'), 'stock' => $request->input('stock', 'keep')]];
    }
}
