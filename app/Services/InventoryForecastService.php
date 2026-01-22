<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductForecast;
use App\Models\Tenant;
use App\Models\User;
use App\Helpers\MailHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class InventoryForecastService
{
    // 1.64 covers 95% of demand variability (Service Level)
    private const SERVICE_LEVEL_Z_SCORE = 1.64;

    public function forecastForTenant(int $tenantId, int $windowDays = 30): void
    {
        // Optimization: Use chunkById and eager load variations to prevent memory exhaustion
        Product::where('tenant_id', $tenantId)
            ->with(['variations'])
            ->chunkById(100, function ($products) use ($windowDays) {
                foreach ($products as $product) {
                    if ($product->variations->isNotEmpty()) {
                        foreach ($product->variations as $variation) {
                            $this->processForecast(
                                $product,
                                (float) $variation->quantity, // Cast decimal correctly
                                $windowDays,
                                $variation->id
                            );
                        }
                    } else {
                        $this->processForecast(
                            $product,
                            (float) $product->quantity, // Cast decimal correctly
                            $windowDays
                        );
                    }
                }
            });
    }

    private function processForecast(Product $product, float $currentQty, int $windowDays, $variationId = null): void
    {
        $dailySales = $this->getDailySalesArray($product->tenant_id, $product->id, $windowDays, $variationId);
        $totalSales = array_sum($dailySales);

        if ($totalSales <= 0) {
            $this->persistDormantForecast($product, $currentQty, $variationId);
            return;
        }

        // --- STEP 1: CALCULATE WEIGHTED DEMAND ---
        // We split the window into 3 segments: Recent (last 7), Mid, and Old.
        // This makes the algorithm smarter about trends (upward or downward).
        $segments = array_chunk(array_reverse($dailySales), 7);
        $weights = [0.5, 0.3, 0.15, 0.05]; // Give 50% weight to the most recent week

        $weightedDemand = 0;
        $activeWeights = 0;

        foreach ($segments as $index => $segment) {
            if (isset($weights[$index])) {
                $weightedDemand += (array_sum($segment) / count($segment)) * $weights[$index];
                $activeWeights += $weights[$index];
            }
        }
        $adjustedDemand = $weightedDemand / ($activeWeights ?: 1);

        // --- STEP 2: CALCULATE DEMAND VARIABILITY (Standard Deviation) ---
        $mean = $totalSales / count($dailySales);
        $variance = 0.0;
        foreach ($dailySales as $sale) {
            $variance += pow($sale - $mean, 2);
        }
        $demandStdDev = sqrt($variance / count($dailySales));

        // --- STEP 3: LEAD TIME & SAFETY STOCK ---
        $leadTime = max((float) ($product->lead_time_days ?? 7), 1.0);

        // Safety Stock formula: Z * StdDev * sqrt(LeadTime)
        // Helps avoid stockouts during demand spikes or supply delays
        $calculatedSafetyStock = self::SERVICE_LEVEL_Z_SCORE * $demandStdDev * sqrt($leadTime);

        // Floor it at 10% of monthly demand or at least a small buffer
        $safetyStock = max($calculatedSafetyStock, ($adjustedDemand * $leadTime * 0.1));

        // Reorder Point (ROP) = (Demand * Lead Time) + Safety Stock
        $reorderPoint = ($adjustedDemand * $leadTime) + $safetyStock;

        // --- STEP 4: PREDICTED DAYS TO STOCKOUT ---
        $daysRemaining = $adjustedDemand > 0 ? ($currentQty / $adjustedDemand) : 999;

        // --- STEP 5: RISK EVALUATION ---
        $risk = 'ok';
        if ($currentQty <= 0) {
            $risk = 'out_of_stock';
        } elseif ($daysRemaining <= ($leadTime * 0.5)) {
            $risk = 'critical'; // Will run out before new stock arrives even if ordered now
        } elseif ($currentQty <= $reorderPoint) {
            $risk = 'warning'; // Time to reorder
        }

        if ($risk !== 'ok') {
            $this->persistAndNotify(
                $product,
                $risk,
                $reorderPoint,
                $safetyStock,
                $adjustedDemand,
                $currentQty,
                $daysRemaining,
                $variationId
            );
        }
    }

    private function getDailySalesArray(int $tenantId, int $productId, int $windowDays, $variationId = null): array
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($windowDays - 1);

        $query = Sale::select(
            DB::raw('DATE(sale_date) as day'),
            DB::raw('SUM(quantity) as total_sold')
        )
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()]);

        if ($variationId) {
            $query->where('variation_id', $variationId);
        } else {
            $query->whereNull('variation_id');
        }

        $sales = $query->groupBy('day')->pluck('total_sold', 'day')->toArray();

        $filledSales = [];
        for ($i = 0; $i < $windowDays; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            // Cast to float to support decimal quantities from DB
            $filledSales[] = (float) ($sales[$date] ?? 0.0);
        }

        return $filledSales;
    }

    private function persistAndNotify($product, $risk, $rop, $ss, $avgDemand, $currentQty, $daysLeft, $variationId)
    {
        // Don't spam: check if we notified about this product/variation risk level in the last 48 hours
        $alreadyNotified = ProductForecast::where('product_id', $product->id)
            ->where('product_variation_id', $variationId)
            ->where('stock_risk_level', $risk)
            ->where('created_at', '>', now()->subHours(48))
            ->exists();

        if (!$alreadyNotified) {
            $forecast = ProductForecast::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'product_variation_id' => $variationId,
                'window_days' => 30,
                'avg_daily_sales' => $avgDemand,
                'current_quantity' => $currentQty,
                'stock_risk_level' => $risk,
                'reorder_point' => $rop,
                'safety_stock' => $ss,
                'predicted_days_to_stockout' => $daysLeft,
                'forecasted_at' => now(),
            ]);

            // Only email for serious risks
            if (in_array($risk, ['warning', 'critical', 'out_of_stock'])) {
                $admins = User::where('tenant_id', $product->tenant_id)
                    ->where('role', 'Administrator')
                    ->where('is_active', true)
                    ->get();

                foreach ($admins as $admin) {
                    MailHelper::sendLowStockForecastEmail($admin, $forecast);
                }
            }
        }
    }

    private function persistDormantForecast(Product $product, $currentQty, $variationId): void
    {
        $exists = ProductForecast::where('product_id', $product->id)
            ->where('product_variation_id', $variationId)
            ->where('forecasted_at', '>', now()->subDays(3))
            ->exists();

        if ($exists)
            return;

        ProductForecast::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_variation_id' => $variationId,
            'window_days' => 30,
            'avg_daily_sales' => 0,
            'current_quantity' => $currentQty,
            'stock_risk_level' => 'inactive',
            'reorder_point' => 0,
            'safety_stock' => 0,
            'forecasted_at' => now(),
        ]);
    }
}