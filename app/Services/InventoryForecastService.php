<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ProductForecast;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;


class InventoryForecastService
{
    // Define standard Z-scores for common service levels.
    // 1.28 = 90% Service Level (Accepts 10% chance of stockout)
    // 1.64 = 95% Service Level (Accepts 5% chance of stockout)
    // 2.33 = 99% Service Level (Accepts 1% chance of stockout)
    private const SERVICE_LEVEL_Z_SCORE = 1.64; // Defaulting to 95% Service Level

    /**
     * Calculates the standard deviation of daily sales for a product.
     * * @param int $tenantId
     * @param int $productId
     * @param int $windowDays
     * @return float
     */
    private function calculateDemandStandardDeviation(int $tenantId, int $productId, int $windowDays): float
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($windowDays - 1);

        // Fetch daily sales for the product over the window
        $dailySales = Sale::select(
            DB::raw('DATE(sale_date) as day'),
            DB::raw('SUM(quantity) as total_sold')
        )
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->groupBy('day')
            ->pluck('total_sold')
            ->map(fn($qty) => (float) $qty)
            ->toArray();

        // If no sales occurred, demand deviation is 0
        if (empty($dailySales)) {
            return 0.0;
        }

        $n = count($dailySales);
        $mean = array_sum($dailySales) / $n;
        $variance = 0.0;

        // Calculate Variance
        foreach ($dailySales as $sale) {
            $variance += pow($sale - $mean, 2);
        }

        // Standard Deviation is the square root of the variance
        return sqrt($variance / $n);
    }

    public function forecastForTenant(int $tenantId, int $windowDays = 30): void
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($windowDays - 1);

        // Aggregate total sales for all products to calculate AVG Daily Sales efficiently
        $salesAgg = Sale::select('product_id', DB::raw('SUM(quantity) as total_sold'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->groupBy('product_id')
            ->pluck('total_sold', 'product_id');

        $products = Product::where('tenant_id', $tenantId)->get();

        foreach ($products as $product) {
            $productId = $product->id;
            $totalSold = (float) ($salesAgg[$productId] ?? 0);
            $days = max($windowDays, 1);

            $avgDailySales = $totalSold > 0 ? $totalSold / $days : 0.0;
            $currentQty = (float) $product->quantity;

            // --- PRODUCT SETTINGS (from the new migration) ---
            $leadTimeDays = (int) ($product->lead_time_days ?? 7); // Default 7 days
            $minStockThreshold = (int) ($product->min_stock_threshold ?? 10); // Default 10 units

            // --- ADVANCED SAFETY STOCK CALCULATION ---

            // 1. Calculate Standard Deviation of Daily Demand (sigma_L)
            $demandStdDev = $this->calculateDemandStandardDeviation($tenantId, $productId, $windowDays);

            // 2. Calculate Safety Stock (SS)
            // SS = Z * sigma_L * sqrt(Lead Time)
            $safetyStockFromDemand = 0;
            if ($demandStdDev > 0) {
                $safetyStockFromDemand = self::SERVICE_LEVEL_Z_SCORE
                    * $demandStdDev
                    * sqrt($leadTimeDays);
            }

            // The effective Safety Stock is the greater of the Min Stock Threshold OR the calculated value.
            // This ensures products with very low demand still meet the manager's minimum (e.g., 10 units).
            $safetyStock = max($minStockThreshold, ceil($safetyStockFromDemand));

            // --- REORDER POINT (ROP) CALCULATION ---
            // ROP = (AVG Daily Sales * Lead Time) + Safety Stock
            $leadTimeDemand = $avgDailySales * $leadTimeDays;
            $reorderPoint = ceil($leadTimeDemand + $safetyStock);


            // --- DAYS TO STOCKOUT & RISK ASSESSMENT ---

            $daysToStockOut = null;
            if ($avgDailySales > 0) {
                $daysToStockOut = $currentQty / $avgDailySales;
            }

            $risk = 'ok';

            if ($currentQty <= 0) {
                $risk = 'critical';
            } elseif ($currentQty < $safetyStock) {
                // Critical if current stock is below the minimum buffer.
                $risk = 'critical';
            } elseif ($currentQty <= $reorderPoint) {
                // Warning if current stock hits or crosses the ROP trigger.
                $risk = 'warning';
            }

            // --- PERSIST FORECAST ---
            if (in_array($risk, ['warning', 'critical'])) {
                $forecast = ProductForecast::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'window_days' => $windowDays,
                    'avg_daily_sales' => $avgDailySales ?: null,
                    'predicted_days_to_stockout' => $daysToStockOut,
                    'current_quantity' => (int) $currentQty,
                    'stock_risk_level' => $risk,
                    'reorder_point' => (int) $reorderPoint,
                    'safety_stock' => (int) $safetyStock,
                    'forecasted_at' => now(),
                ]);
            }


            // Notify tenant admins only when at risk
            if (in_array($risk, ['warning', 'critical']) && $daysToStockOut !== null) {
                $admins = User::where('tenant_id', $tenantId)
                    ->where('role', 'Administrator')
                    ->get();

                foreach ($admins as $admin) {
                    MailHelper::sendLowStockForecastEmail($admin, $forecast);
                }
            }
        }
    }
}