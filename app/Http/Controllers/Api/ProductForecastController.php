<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductForecast;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductForecastController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;

        $perPage = $request->get('per_page', 20);
        $risk = $request->get('risk'); // ok|warning|critical

        $query = ProductForecast::with('product')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')
                    ->from('product_forecasts')
                    ->whereColumn('product_id', 'product_forecasts.product_id')
                    ->groupBy('product_id');
            });

        if ($risk) {
            $query->where('stock_risk_level', $risk);
        }

        $forecasts = $query->orderBy('stock_risk_level')->paginate($perPage);

        return response()->json($forecasts);
    }

    public function dashboardInsights()
    {
        // 1. Authentication and Scope
        $tenantId = auth()->user()->tenant_id;

        // 2. Optimized Eloquent Query (Uses join to find MAX(id) per product_id efficiently)
        $latestPerProduct = ProductForecast::select('product_forecasts.*')
            ->join(
                DB::raw('(SELECT product_id, MAX(id) as id FROM product_forecasts GROUP BY product_id) as latest'),
                'product_forecasts.id',
                '=',
                'latest.id'
            )
            ->where('product_forecasts.tenant_id', $tenantId)
            ->whereIn('stock_risk_level', ['warning', 'critical'])
            ->with('product:id,name') // Only fetch 'id' and 'name' from the related product
            // Prioritize Critical over Warning, then sort by days to stockout (closest first)
            ->orderByRaw("FIELD(stock_risk_level, 'critical', 'warning')")
            ->orderBy('predicted_days_to_stockout', 'asc')
            ->limit(5)
            ->get();

        // 3. Transform the Collection to match the React UI's expected structure
        $transformedData = $latestPerProduct->map(function ($forecast) {
            return [
                'id' => $forecast->id,
                'product_id' => $forecast->product_id,

                // Explicitly cast to ensure correct types for JavaScript consumption
                'avg_daily_sales' => (float) $forecast->avg_daily_sales,
                'predicted_days_to_stockout' => (float) $forecast->predicted_days_to_stockout,
                'current_quantity' => (int) $forecast->current_quantity,

                'stock_risk_level' => $forecast->stock_risk_level,
                'forecasted_at' => $forecast->forecasted_at,

                'reorder_point' => (int) $forecast->reorder_point,
                'safety_stock' => (int) $forecast->safety_stock,

                // Ensure the 'product' object exists with 'name'
                'product' => [
                    'name' => $forecast->product->name ?? 'N/A',
                    // Only include necessary fields
                ]
            ];
        });

        return response()->json([
            'data' => $transformedData,
        ]);
    }
}
