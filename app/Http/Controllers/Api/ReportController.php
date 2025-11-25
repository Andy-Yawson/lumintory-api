<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\SalesReport;
use App\Exports\StockReport;
use App\Exports\ReturnsReport;
use App\Exports\TopProductsReport;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Services\PlanLimit;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $tenant = $user->tenant;

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $salesCount = Sale::whereTenantId($tenant->id)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();
        $returnsCount = ReturnItem::whereTenantId($tenant->id)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();

        return response()->json([
            'sales_this_month' => $salesCount,
            'returns_this_month' => $returnsCount,
        ]);
    }

    protected function planLevel()
    {
        $user = Auth::user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context');
        }

        $tenant = $user->tenant;

        return [
            'tenant' => $tenant,
            'level' => PlanLimit::getLimit($tenant, 'reports', 'basic'), // basic|advanced|custom
        ];
    }

    protected function validateDateRangeForPlan(Request $request)
    {
        $dates = $request->only(['start_date', 'end_date']);
        $start = Carbon::parse($dates['start_date']);
        $end = Carbon::parse($dates['end_date']);

        $diffInDays = $start->diffInDays($end);

        $plan = $this->planLevel();

        // example: basic can only query 31 days range
        if ($plan['level'] === 'basic' && $diffInDays > 31) {
            abort(422, 'Basic plan reports are limited to a 31-day date range. Please choose a shorter range or upgrade to Pro.');
        }
    }

    protected function ensureAdvancedFor(string $reportType)
    {
        $plan = $this->planLevel();

        // treat "custom" as advanced+
        if (in_array($plan['level'], ['advanced', 'custom'])) {
            return;
        }

        // For basic, block advanced reports
        abort(403, "The {$reportType} report is available on Pro plans only. Please upgrade to access advanced reporting.");
    }

    protected function ensureFormatAllowed(Request $request)
    {
        $plan = $this->planLevel();

        // Basic: only excel
        if ($plan['level'] === 'basic' && $request->format === 'pdf') {
            abort(403, 'PDF export is available on Pro plans only. Please switch to Excel or upgrade your plan.');
        }
    }

    public function sales(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:excel,pdf',
        ]);

        $this->validateDateRangeForPlan($request);
        $this->ensureFormatAllowed($request);

        $export = new SalesReport($request->start_date, $request->end_date);

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.sales', ['sales' => $export->collection()]);
            return $pdf->download('sales-report.pdf');
        }

        return Excel::download($export, 'sales-report.xlsx');
    }

    public function topProducts(Request $request)
    {
        // top products considered "advanced" report
        $this->ensureAdvancedFor('Top Products');
        $this->ensureFormatAllowed($request);

        $request->validate([
            'format' => 'required|in:excel,pdf',
        ]);

        $export = new TopProductsReport();

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.top_products', ['products' => $export->collection()]);
            return $pdf->download('top-products-report.pdf');
        }

        return Excel::download($export, 'top-products-report.xlsx');
    }

    public function returns(Request $request)
    {
        // returns report considered advanced
        $this->ensureAdvancedFor('Returns');

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'required|in:excel,pdf',
        ]);

        $this->validateDateRangeForPlan($request);
        $this->ensureFormatAllowed($request);

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

        $this->ensureFormatAllowed($request);

        $export = new StockReport();

        if ($request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.stock', ['products' => $export->collection()]);
            return $pdf->download('stock-balance.pdf');
        }

        return Excel::download($export, 'stock-balance.xlsx');
    }
}
