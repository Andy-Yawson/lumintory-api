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
        $products = Product::where('tenant_id', $tenantId)->get();

        foreach ($products as $product) {
            $dailySales = $this->getDailySalesArray($tenantId, $product->id, $windowDays);

            // 1. Calculate Weighted Average (Recent 7 days count double)
            $totalDays = count($dailySales);
            $recentDays = array_slice($dailySales, -7);
            $avgDailySales = array_sum($dailySales) / $totalDays;
            $recentAvg = array_sum($recentDays) / 7;

            // Blend the two: 70% overall trend, 30% recent activity
            $adjustedDemand = ($avgDailySales * 0.7) + ($recentAvg * 0.3);

            // 2. Standard Deviation (using the zero-filled array)
            $mean = array_sum($dailySales) / $totalDays;
            $variance = array_reduce($dailySales, fn($carry, $item) => $carry + pow($item - $mean, 2), 0.0) / $totalDays;
            $demandStdDev = sqrt($variance);

            // 3. Safety Stock & ROP
            $leadTime = (int) ($product->lead_time_days ?? 7);
            $safetyStock = ceil(self::SERVICE_LEVEL_Z_SCORE * $demandStdDev * sqrt($leadTime));
            $safetyStock = max($safetyStock, (int) $product->min_stock_threshold);

            $reorderPoint = ceil(($adjustedDemand * $leadTime) + $safetyStock);

            // 4. Determine Risk
            $currentQty = (float) $product->quantity;
            $risk = 'ok';
            if ($currentQty <= $safetyStock)
                $risk = 'critical';
            elseif ($currentQty <= $reorderPoint)
                $risk = 'warning';

            // 5. Smart Notification (Prevention of Spam)
            if (in_array($risk, ['warning', 'critical'])) {
                $this->persistAndNotify($product, $risk, $reorderPoint, $safetyStock, $adjustedDemand);
            }
        }
    }

    private function getDailySalesArray(int $tenantId, int $productId, int $windowDays): array
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($windowDays - 1);

        $sales = Sale::select(
            DB::raw('DATE(sale_date) as day'),
            DB::raw('SUM(quantity) as total_sold')
        )
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->groupBy('day')
            ->pluck('total_sold', 'day')
            ->toArray();

        // Zero-fill missing days to ensure the Standard Deviation is accurate
        $filledSales = [];
        for ($i = 0; $i < $windowDays; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $filledSales[] = (float) ($sales[$date] ?? 0.0);
        }

        return $filledSales;
    }

    private function persistAndNotify($product, $risk, $rop, $ss, $avgDemand)
    {
        // Check if we already notified about this product in the last 24 hours
        $alreadyNotified = ProductForecast::where('product_id', $product->id)
            ->where('created_at', '>', now()->subHours(24))
            ->exists();

        if (!$alreadyNotified) {
            // Create Record and Send Mail here...
            $forecast = ProductForecast::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'window_days' => 30,
                'avg_daily_sales' => $avgDemand,
                'predicted_days_to_stockout' => null,
                'current_quantity' => $product->quantity,
                'stock_risk_level' => $risk,
                'reorder_point' => $rop,
                'safety_stock' => $ss,
                'forecasted_at' => now(),
            ]);

            // Notify tenant admins only when at risk
            if (in_array($risk, ['warning', 'critical'])) {
                $admins = User::where('tenant_id', $product->tenant_id)
                    ->where('role', 'Administrator')
                    ->get();

                foreach ($admins as $admin) {
                    MailHelper::sendLowStockForecastEmail($admin, $forecast);
                }
            }
        }
    }
}