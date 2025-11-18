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
        $tenantId = auth()->user()->tenant_id;

        $latestPerProduct = ProductForecast::select('product_forecasts.*')
            ->join(
                DB::raw('(SELECT MAX(id) as id FROM product_forecasts GROUP BY product_id) as latest'),
                'product_forecasts.id',
                '=',
                'latest.id'
            )
            ->where('product_forecasts.tenant_id', $tenantId)
            ->whereIn('stock_risk_level', ['warning', 'critical'])
            ->with('product')
            ->orderByRaw("FIELD(stock_risk_level, 'critical', 'warning')")
            ->orderBy('predicted_days_to_stockout', 'asc')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => $latestPerProduct,
        ]);
    }
}
