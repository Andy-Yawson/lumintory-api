<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\SalesReport;
use App\Exports\StockReport;
use App\Exports\ReturnsReport;
use App\Exports\TopProductsReport;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:excel,pdf',
        ]);

        $export = new SalesReport($request->start_date, $request->end_date);

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.sales', ['sales' => $export->collection()]);
            return $pdf->download('sales-report.pdf');
        }

        return Excel::download($export, 'sales-report.xlsx');
    }

    public function topProducts()
    {
        $export = new TopProductsReport();
        return Excel::download($export, 'top-products.xlsx');
    }

    public function returns(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:excel,pdf',
        ]);

        $export = new ReturnsReport($request->start_date, $request->end_date);

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.returns', ['returns' => $export->collection()]);
            return $pdf->download('returns-report.pdf');
        }

        return Excel::download($export, 'returns-report.xlsx');
    }

    public function stock(Request $request)
    {
        $request->validate([
            'format' => 'required|in:excel,pdf',
        ]);

        $export = new StockReport();

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.stock', ['products' => $export->collection()]);
            return $pdf->download('stock-balance.pdf');
        }

        return Excel::download($export, 'stock-balance.xlsx');
    }
}
