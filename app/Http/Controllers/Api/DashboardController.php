<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ReturnItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;
        $filter = $request->query('filter', 'overall');
        $startDateParam = $request->query('start_date');
        $endDateParam = $request->query('end_date');

        // Optional: add specific payment method filter to the query if needed later
        $paymentMethodFilter = $request->query('payment_method');

        $cacheKey = "dashboard_stats_tenant_{$tenantId}_{$filter}_{$startDateParam}_{$endDateParam}_{$paymentMethodFilter}";

        return Cache::remember($cacheKey, now()->addMinutes(1), function () use ($tenantId, $filter, $startDateParam, $endDateParam, $paymentMethodFilter) {

            // Define Date Context
            $startDate = null;
            $endDate = now();

            if ($filter === 'today') {
                $startDate = Carbon::today();
                $endDate = Carbon::today()->endOfDay();
            } elseif ($filter === 'custom' && $startDateParam && $endDateParam) {
                $startDate = Carbon::parse($startDateParam)->startOfDay();
                $endDate = Carbon::parse($endDateParam)->endOfDay();
            }

            // 1. Static Stats
            $totalProducts = Product::where('tenant_id', $tenantId)->count();
            $totalStock = Product::where('tenant_id', $tenantId)->sum('quantity');

            $tenant = Tenant::find($tenantId);
            $threshold = $tenant?->settings['low_stock_threshold'] ?? 10;
            // $lowStockCount = Product::where('tenant_id', $tenantId)
            //     ->where('quantity', '<', $threshold)
            //     ->count();

            // 2. Filtered Stats
            $salesQuery = Sale::where('tenant_id', $tenantId);
            $returnsQuery = ReturnItem::where('tenant_id', $tenantId);
            $topProductQuery = Sale::where('tenant_id', $tenantId);

            // Apply Global Date Filter
            if ($startDate) {
                $salesQuery->whereBetween('sale_date', [$startDate, $endDate]);
                $returnsQuery->whereBetween('return_date', [$startDate, $endDate]);
                $topProductQuery->whereBetween('sale_date', [$startDate, $endDate]);
            }

            // Apply Payment Method Filter (If user specifically wants to see dashboard for just "Cash")
            if ($paymentMethodFilter) {
                $salesQuery->where('payment_method', $paymentMethodFilter);
                // Returns usually filter by refund_method if filtered by payment method
                $returnsQuery->where('refund_method', $paymentMethodFilter);
            }

            /** --- NEW: Payment Method Breakdowns --- **/

            // Group Sales by Payment Method
            $salesByMethod = (clone $salesQuery)
                ->selectRaw('payment_method, SUM(total_amount) as total, COUNT(*) as count')
                ->groupBy('payment_method')
                ->get();

            // Group Returns by Refund Method
            $returnsByMethod = (clone $returnsQuery)
                ->selectRaw('refund_method, SUM(refund_amount) as total, COUNT(*) as count')
                ->groupBy('refund_method')
                ->get();

            /** --- END NEW DATA --- **/

            $salesAmount = $salesQuery->sum('total_amount');
            $salesCount = $salesQuery->count();
            $returnsAmount = $returnsQuery->sum('refund_amount');

            $topProduct = $topProductQuery->with('product:id,name')
                ->selectRaw('product_id, SUM(quantity) as total_sold')
                ->groupBy('product_id')
                ->orderByDesc('total_sold')
                ->first();

            // 3. Graph Data
            $graphStart = $startDate ?: now()->subDays(6);
            $graphEnd = $endDate ?: now();

            if ($graphStart->diffInDays($graphEnd) > 30) {
                $graphStart = $graphEnd->copy()->subDays(30);
            }

            $salesData = Sale::where('tenant_id', $tenantId)
                ->whereBetween('sale_date', [$graphStart, $graphEnd])
                ->selectRaw('DATE(sale_date) as date, SUM(total_amount) as total_sales')
                ->groupBy('date')->get();

            $returnsData = ReturnItem::where('tenant_id', $tenantId)
                ->whereBetween('return_date', [$graphStart, $graphEnd])
                ->selectRaw('DATE(return_date) as date, SUM(refund_amount) as total_returns')
                ->groupBy('date')->get();

            $chartDates = [];
            $tempDate = $graphStart->copy();
            while ($tempDate <= $graphEnd) {
                $dateStr = $tempDate->format('Y-m-d');
                $chartDates[] = [
                    'date' => $dateStr,
                    'sales' => (float) ($salesData->firstWhere('date', $dateStr)->total_sales ?? 0),
                    'returns' => (float) ($returnsData->firstWhere('date', $dateStr)->total_returns ?? 0),
                ];
                $tempDate->addDay();
            }

            return response()->json([
                'total_products' => $totalProducts,
                'total_stock' => (float) $totalStock, // Changed to float for "halved" items
                'sales_amount' => round($salesAmount, 2),
                'sales_count' => $salesCount,
                'returns_amount' => round($returnsAmount, 2),
                // 'low_stock_count' => $lowStockCount,
                'top_product' => $topProduct?->product->name ?? 'N/A',
                'top_product_sold' => (float) ($topProduct?->total_sold ?? 0),
                'currency' => $tenant->settings['currency'] ?? 'GHS',
                'currency_symbol' => $tenant->settings['currency_symbol'] ?? '₵',
                'generated_at' => now()->format('Y-m-d H:i'),
                'graph' => $chartDates,
                'filter_applied' => $filter,
                // New fields for the UI to render pie charts or list
                'sales_by_method' => $salesByMethod,
                'returns_by_method' => $returnsByMethod
            ]);
        });
    }
}
