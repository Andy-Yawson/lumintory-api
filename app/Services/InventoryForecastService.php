<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductForecast;
use App\Models\User;
use App\Helpers\MailHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryForecastService
{
    private const SERVICE_LEVEL_Z_SCORE = 1.64;

    public function forecastForTenant(int $tenantId, int $windowDays = 30): void
    {
        Product::where('tenant_id', $tenantId)
            ->with(['variations'])
            ->chunkById(100, function ($products) use ($windowDays) {
                foreach ($products as $product) {
                    $variations = $product->variations;
                    $threshold = $product->min_stock_threshold ?? 10;

                    if ($variations && $variations->isNotEmpty()) {
                        foreach ($variations as $variation) {
                            $this->processForecast(
                                $product,
                                (float) ($variation->quantity ?? 0),
                                $windowDays,
                                $threshold,
                                $variation->id
                            );
                        }
                    } else {
                        $this->processForecast(
                            $product,
                            (float) ($product->quantity ?? 0),
                            $windowDays,
                            $threshold,
                            null
                        );
                    }
                }
            });
    }

    private function processForecast(Product $product, float $currentQty, int $windowDays, $threshold, $variationId = null): void
    {
        $dailySales = $this->getDailySalesArray($product->tenant_id, $product->id, $windowDays, $variationId);
        $totalSales = array_sum($dailySales);

        // --- STEP 1: CALCULATE WEIGHTED DEMAND ---
        $segments = array_chunk(array_reverse($dailySales), 7);
        $weights = [0.5, 0.3, 0.15, 0.05];
        $weightedDemand = 0;
        $activeWeights = 0;

        foreach ($segments as $index => $segment) {
            if (isset($weights[$index]) && count($segment) > 0) {
                $weightedDemand += (array_sum($segment) / count($segment)) * $weights[$index];
                $activeWeights += $weights[$index];
            }
        }
        $adjustedDemand = $weightedDemand / ($activeWeights ?: 1);

        // --- STEP 2: CALCULATE DEMAND VARIABILITY ---
        $count = count($dailySales) ?: 1;
        $mean = $totalSales / $count;
        $variance = 0.0;
        foreach ($dailySales as $sale) {
            $variance += pow($sale - $mean, 2);
        }
        $demandStdDev = sqrt($variance / $count);

        // --- STEP 3: LEAD TIME & SAFETY STOCK ---
        $leadTime = max((float) ($product->lead_time_days ?? 7), 1.0);
        $calculatedSafetyStock = self::SERVICE_LEVEL_Z_SCORE * $demandStdDev * sqrt($leadTime);
        $safetyStock = max($calculatedSafetyStock, ($adjustedDemand * $leadTime * 0.1));
        $reorderPoint = ($adjustedDemand * $leadTime) + $safetyStock;

        // --- STEP 4: PREDICTED DAYS TO STOCKOUT ---
        $daysRemaining = $adjustedDemand > 0 ? ($currentQty / $adjustedDemand) : 999;

        // --- STEP 5: RISK EVALUATION (Synced with your lowStock logic) ---
        $risk = 'ok';

        // Rule A: Static Threshold Check (Matches your lowStock() controller)
        $isBelowThreshold = ($currentQty <= $threshold);

        // Rule B: Stockout Logic
        if ($currentQty <= 0) {
            $risk = 'critical';
        } elseif ($daysRemaining <= ($leadTime * 0.5)) {
            $risk = 'critical';
        } elseif ($isBelowThreshold || $currentQty <= $reorderPoint) {
            $risk = 'warning';
        }

        // Only persist and notify if there's an actual risk identified
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

        $query = Sale::where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereBetween('sale_date', [$startDate->startOfDay(), $endDate->endOfDay()]);

        if ($variationId) {
            $query->where('variation_id', $variationId);
        }

        $sales = $query->select(
            DB::raw('DATE(sale_date) as day'),
            DB::raw('SUM(quantity) as total_sold')
        )
            ->groupBy('day')
            ->pluck('total_sold', 'day')
            ->toArray();

        $filledSales = [];
        for ($i = 0; $i < $windowDays; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $filledSales[] = (float) ($sales[$date] ?? 0.0);
        }

        return $filledSales;
    }

    private function persistAndNotify($product, $risk, $rop, $ss, $avgDemand, $currentQty, $daysLeft, $variationId)
    {
        $latest = ProductForecast::where('product_id', $product->id)
            ->where('product_variation_id', $variationId)
            ->orderBy('created_at', 'desc')
            ->first();

        // Create if: No record, status worsened, or 48h passed
        $shouldCreate = !$latest ||
            ($risk === 'critical' && $latest->stock_risk_level === 'warning') ||
            $latest->stock_risk_level !== $risk ||
            $latest->created_at->lt(now()->subHours(48));

        if ($shouldCreate) {
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

            $admins = User::where('tenant_id', $product->tenant_id)
                ->where('role', 'Administrator')
                ->get();

            foreach ($admins as $admin) {
                MailHelper::sendLowStockForecastEmail($admin, $forecast);
            }
        }
    }
}