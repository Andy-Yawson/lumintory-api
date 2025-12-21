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
        $filter = $request->query('filter', 'overall'); // overall, today, custom
        $startDateParam = $request->query('start_date');
        $endDateParam = $request->query('end_date');

        // Create a unique cache key based on filter parameters
        $cacheKey = "dashboard_stats_tenant_{$tenantId}_{$filter}_{$startDateParam}_{$endDateParam}";

        return Cache::remember($cacheKey, now()->addMinutes(1), function () use ($tenantId, $filter, $startDateParam, $endDateParam) {

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

            // 1. Static Stats (Not affected by date filter)
            $totalProducts = Product::where('tenant_id', $tenantId)->count();
            $totalStock = Product::where('tenant_id', $tenantId)->sum('quantity');

            $tenant = Tenant::find($tenantId);
            $threshold = $tenant?->settings['low_stock_threshold'] ?? 10;
            $lowStockCount = Product::where('tenant_id', $tenantId)
                ->where('quantity', '<', $threshold)
                ->count();

            // 2. Filtered Stats (Affected by date range)
            $salesQuery = Sale::where('tenant_id', $tenantId);
            $returnsQuery = ReturnItem::where('tenant_id', $tenantId);
            $topProductQuery = Sale::where('tenant_id', $tenantId);

            if ($startDate) {
                $salesQuery->whereBetween('sale_date', [$startDate, $endDate]);
                $returnsQuery->whereBetween('return_date', [$startDate, $endDate]);
                $topProductQuery->whereBetween('sale_date', [$startDate, $endDate]);
            }

            $salesAmount = $salesQuery->sum('total_amount');
            $salesCount = $salesQuery->count();
            $returnsAmount = $returnsQuery->sum('refund_amount');

            $topProduct = $topProductQuery->with('product:id,name')
                ->selectRaw('product_id, SUM(quantity) as total_sold')
                ->groupBy('product_id')
                ->orderByDesc('total_sold')
                ->first();

            // 3. Graph Data (Always show trend for context)
            // If custom range is long, we show that range. If short/overall, show last 7 days.
            $graphStart = $startDate ?: now()->subDays(6);
            $graphEnd = $endDate ?: now();

            // Limit graph to max 30 days to prevent browser lag
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

            // Generate full date range for chart
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
                'total_stock' => (int) $totalStock,
                'sales_amount' => round($salesAmount, 2),
                'sales_count' => $salesCount,
                'returns_amount' => round($returnsAmount, 2),
                'low_stock_count' => $lowStockCount,
                'top_product' => $topProduct?->product->name ?? 'N/A',
                'top_product_sold' => $topProduct?->total_sold ?? 0,
                'currency' => $tenant->settings['currency'] ?? 'GHS',
                'currency_symbol' => $tenant->settings['currency_symbol'] ?? '₵',
                'generated_at' => now()->format('Y-m-d H:i'),
                'graph' => $chartDates,
                'filter_applied' => $filter
            ]);
        });
    }
}
