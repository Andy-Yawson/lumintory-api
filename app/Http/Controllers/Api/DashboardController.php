<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ReturnItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index()
    {
        $tenantId = Auth::user()->tenant_id;
        $cacheKey = "dashboard_stats_tenant_{$tenantId}";


        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($tenantId) {

            $today = Carbon::today();
            $totalProducts = Product::where('tenant_id', $tenantId)->count();

            $totalStock = Product::where('tenant_id', $tenantId)
                ->sum('quantity');

            $todaySales = Sale::where('tenant_id', $tenantId)
                ->whereDate('sale_date', $today)
                ->sum('total_amount');

            $todaySalesCount = Sale::where('tenant_id', $tenantId)
                ->whereDate('sale_date', $today)
                ->count();

            $todayReturns = ReturnItem::where('tenant_id', $tenantId)
                ->whereDate('return_date', $today)
                ->sum('refund_amount');

            $tenant = \App\Models\Tenant::find($tenantId);
            $threshold = $tenant?->settings['low_stock_threshold'] ?? 10;

            $lowStockCount = Product::where('tenant_id', $tenantId)
                ->where('quantity', '<', $threshold)
                ->count();

            $topProduct = Sale::where('tenant_id', $tenantId)
                ->with('product')
                ->selectRaw('product_id, SUM(quantity) as total_sold')
                ->groupBy('product_id')
                ->orderByDesc('total_sold')
                ->first();


            // === GRAPH DATA (Sales vs Returns - last 7 days) ===
            $startDate = now()->subDays(6); // include today + 6 previous days
            $endDate = now();

            $salesData = Sale::where('tenant_id', $tenantId)
                ->whereBetween('sale_date', [$startDate, $endDate])
                ->selectRaw('DATE(sale_date) as date, SUM(total_amount) as total_sales')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $returnsData = ReturnItem::where('tenant_id', $tenantId)
                ->whereBetween('return_date', [$startDate, $endDate])
                ->selectRaw('DATE(return_date) as date, SUM(refund_amount) as total_returns')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Merge sales & returns data into one array by date
            $dates = collect();
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $dates->push([
                    'date' => $date,
                    'sales' => (float) ($salesData->firstWhere('date', $date)->total_sales ?? 0),
                    'returns' => (float) ($returnsData->firstWhere('date', $date)->total_returns ?? 0),
                ]);
            }

            return response()->json([
                'total_products' => $totalProducts,
                'total_stock' => (int) $totalStock,
                'today_sales_amount' => round($todaySales, 2),
                'today_sales_count' => $todaySalesCount,
                'today_returns' => round($todayReturns, 2),
                'low_stock_count' => $lowStockCount,
                'top_product' => $topProduct?->product->name ?? 'N/A',
                'top_product_sold' => $topProduct?->total_sold ?? 0,
                'currency' => Auth::user()->tenant->settings['currency'] ?? 'GHS',
                'currency_symbol' => Auth::user()->tenant->settings['currency_symbol'] ?? '₵',
                'generated_at' => now()->format('Y-m-d H:i'),
                'graph' => $dates,
            ]);
        });
    }
}
