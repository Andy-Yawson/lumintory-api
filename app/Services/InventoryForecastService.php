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
        $products = Product::where('tenant_id', $tenantId)->with('variations')->get();

        foreach ($products as $product) {
            if ($product->variations->isNotEmpty()) {
                // RUN FORECAST FOR EACH VARIATION
                foreach ($product->variations as $variation) {
                    $this->processForecast(
                        $product,
                        $variation->quantity,
                        $windowDays,
                        $variation->id
                    );
                }
            } else {
                // RUN FORECAST FOR STANDALONE PRODUCT
                $this->processForecast(
                    $product,
                    $product->quantity,
                    $windowDays
                );
            }
        }
    }

    private function processForecast(Product $product, $currentQty, int $windowDays, $variationId = null): void
    {
        $dailySales = $this->getDailySalesArray($product->tenant_id, $product->id, $windowDays, $variationId);
        $totalSales = array_sum($dailySales);
        $totalDays = count($dailySales);

        if ($totalSales === 0) {
            $this->persistDormantForecast($product, $currentQty, $variationId);
            return;
        }

        // STEP 2: DEMAND CALCULATION
        $recentDays = array_slice($dailySales, -7);
        $avgDailySales = $totalSales / $totalDays;
        $recentAvg = array_sum($recentDays) / count($recentDays);
        $adjustedDemand = ($avgDailySales * 0.6) + ($recentAvg * 0.4);

        // STEP 3: DEMAND VARIABILITY
        $mean = $avgDailySales;
        $variance = 0.0;
        foreach ($dailySales as $sale) {
            $variance += pow($sale - $mean, 2);
        }
        $demandStdDev = sqrt($variance / $totalDays);

        // STEP 4: SAFETY STOCK & ROP
        $leadTime = max((int) ($product->lead_time_days ?? 7), 1);
        $calculatedSafetyStock = ceil(self::SERVICE_LEVEL_Z_SCORE * $demandStdDev * sqrt($leadTime));
        $safetyStock = min(max($calculatedSafetyStock, 1), ceil($adjustedDemand * $leadTime));
        $reorderPoint = ceil(($adjustedDemand * $leadTime) + $safetyStock);

        // STEP 5: RISK EVALUATION
        $risk = 'ok';
        if ($currentQty <= $safetyStock) {
            $risk = 'critical';
        } elseif ($currentQty <= $reorderPoint) {
            $risk = 'warning';
        }

        if ($risk !== 'ok') {
            $this->persistAndNotify(
                $product,
                $risk,
                $reorderPoint,
                $safetyStock,
                $adjustedDemand,
                $currentQty,
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

        // IMPORTANT: Filter by variation if provided
        if ($variationId) {
            $query->where('variation_id', $variationId);
        } else {
            // If checking parent, only count sales that HAD NO variation
            $query->whereNull('variation_id');
        }

        $sales = $query->groupBy('day')
            ->pluck('total_sold', 'day')
            ->toArray();

        $filledSales = [];
        for ($i = 0; $i < $windowDays; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $filledSales[] = (float) ($sales[$date] ?? 0.0);
        }

        return $filledSales;
    }

    private function persistAndNotify($product, $risk, $rop, $ss, $avgDemand, $currentQty, $variationId = null)
    {
        $alreadyNotified = ProductForecast::where('product_id', $product->id)
            ->where('product_variation_id', $variationId)
            ->where('created_at', '>', now()->subHours(24))
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
                'forecasted_at' => now(),
            ]);

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

    private function persistDormantForecast(Product $product, $currentQty, $variationId = null): void
    {
        $recent = ProductForecast::where('product_id', $product->id)
            ->where('product_variation_id', $variationId)
            ->where('forecasted_at', '>', now()->subDays(7))
            ->exists();

        if ($recent)
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