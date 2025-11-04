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


        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($tenantId) {

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

            $lowStockCount = Product::where('tenant_id', $tenantId)
                ->where('quantity', '<', function ($q) use ($tenantId) {
                    $threshold = \App\Models\Tenant::find($tenantId)->settings['low_stock_threshold'] ?? 10;
                    $q->select('low_stock_threshold')->from('tenants')->where('id', $tenantId);
                })
                ->count();

            $topProduct = Sale::where('tenant_id', $tenantId)
                ->with('product')
                ->selectRaw('product_id, SUM(quantity) as total_sold')
                ->groupBy('product_id')
                ->orderByDesc('total_sold')
                ->first();

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
                'generated_at' => now()->format('Y-m-d H:i'),
            ]);
        });
    }
}
