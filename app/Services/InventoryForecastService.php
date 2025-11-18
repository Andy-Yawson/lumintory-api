<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\ProductForecast;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Notifications\LowStockForecastNotification;


class InventoryForecastService
{
    public function forecastForTenant(int $tenantId, int $windowDays = 30): void
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($windowDays - 1);

        $salesAgg = Sale::select('product_id', DB::raw('SUM(quantity) as total_sold'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->groupBy('product_id')
            ->pluck('total_sold', 'product_id');

        $products = Product::where('tenant_id', $tenantId)->get();

        foreach ($products as $product) {
            $totalSold = (float) ($salesAgg[$product->id] ?? 0);
            $days = max($windowDays, 1);

            $avgDailySales = $totalSold > 0 ? $totalSold / $days : 0.0;
            $currentQty = (float) $product->quantity;

            if ($avgDailySales > 0 && $currentQty > 0) {
                $daysToStockOut = $currentQty / $avgDailySales;
            } elseif ($avgDailySales > 0 && $currentQty <= 0) {
                $daysToStockOut = 0;
            } else {
                $daysToStockOut = null;
            }

            $risk = 'ok';
            if (!is_null($daysToStockOut)) {
                if ($daysToStockOut <= 3) {
                    $risk = 'critical';
                } elseif ($daysToStockOut <= 7) {
                    $risk = 'warning';
                }
            }

            $forecast = ProductForecast::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'window_days' => $windowDays,
                'avg_daily_sales' => $avgDailySales ?: null,
                'predicted_days_to_stockout' => $daysToStockOut,
                'current_quantity' => (int) $currentQty,
                'stock_risk_level' => $risk,
                'forecasted_at' => now(),
            ]);

            // Notify tenant admins only when at risk
            if (in_array($risk, ['warning', 'critical']) && $daysToStockOut !== null) {
                $admins = User::where('tenant_id', $tenantId)
                    ->where('role', 'Administrator')
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(new LowStockForecastNotification($forecast));
                }
            }
        }
    }
}
