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
    private const SERVICE_LEVEL_Z_SCORE = 1.64;

    public function forecastForTenant(int $tenantId, int $windowDays = 30): void
    {
        $products = Product::where('tenant_id', $tenantId)->get();

        foreach ($products as $product) {

            $dailySales = $this->getDailySalesArray($tenantId, $product->id, $windowDays);

            $totalSales = array_sum($dailySales);
            $totalDays = count($dailySales);

            /*
            |--------------------------------------------------------------------------
            | STEP 1: NO DEMAND → NO RISK
            |--------------------------------------------------------------------------
            */
            if ($totalSales === 0) {
                $this->persistDormantForecast($product);
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | STEP 2: DEMAND CALCULATION
            |--------------------------------------------------------------------------
            */
            $recentDays = array_slice($dailySales, -7);

            $avgDailySales = $totalSales / $totalDays;
            $recentAvg = array_sum($recentDays) / count($recentDays);

            // Bias slightly toward recent activity
            $adjustedDemand = ($avgDailySales * 0.6) + ($recentAvg * 0.4);

            /*
            |--------------------------------------------------------------------------
            | STEP 3: DEMAND VARIABILITY
            |--------------------------------------------------------------------------
            */
            $mean = $avgDailySales;
            $variance = 0.0;

            foreach ($dailySales as $sale) {
                $variance += pow($sale - $mean, 2);
            }

            $demandStdDev = sqrt($variance / $totalDays);

            /*
            |--------------------------------------------------------------------------
            | STEP 4: SAFETY STOCK & ROP
            |--------------------------------------------------------------------------
            */
            $leadTime = max((int) ($product->lead_time_days ?? 7), 1);

            $calculatedSafetyStock = ceil(
                self::SERVICE_LEVEL_Z_SCORE * $demandStdDev * sqrt($leadTime)
            );

            // Safety stock must not exceed expected lead-time demand
            $safetyStock = min(
                max($calculatedSafetyStock, 1),
                ceil($adjustedDemand * $leadTime)
            );

            $reorderPoint = ceil(($adjustedDemand * $leadTime) + $safetyStock);

            /*
            |--------------------------------------------------------------------------
            | STEP 5: RISK EVALUATION
            |--------------------------------------------------------------------------
            */
            $currentQty = (float) $product->quantity;

            $risk = 'ok';

            if ($currentQty <= $safetyStock) {
                $risk = 'critical';
            } elseif ($currentQty <= $reorderPoint) {
                $risk = 'warning';
            }

            /*
            |--------------------------------------------------------------------------
            | STEP 6: PERSIST & NOTIFY (SMART)
            |--------------------------------------------------------------------------
            */
            if ($risk !== 'ok') {
                $this->persistAndNotify(
                    $product,
                    $risk,
                    $reorderPoint,
                    $safetyStock,
                    $adjustedDemand
                );
            }
        }
    }

    private function persistDormantForecast(Product $product): void
    {
        $recent = ProductForecast::where('product_id', $product->id)
            ->where('forecasted_at', '>', now()->subDays(7))
            ->exists();

        if ($recent) {
            return;
        }

        ProductForecast::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'window_days' => 30,
            'avg_daily_sales' => 0,
            'predicted_days_to_stockout' => null,
            'current_quantity' => $product->quantity,
            'stock_risk_level' => 'inactive',
            'reorder_point' => 0,
            'safety_stock' => 0,
            'forecasted_at' => now(),
        ]);
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
            // Create Record and Send Mail
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