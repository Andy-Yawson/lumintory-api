<?php

namespace App\Services\Integrations;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * The one place products and orders from an outside system become Zinnvy rows.
 * The Custom API endpoints, Shopify and WooCommerce all funnel through here,
 * so a product is matched, updated and stock-adjusted the same way no matter
 * where it came from.
 */
class CatalogSync
{
    /**
     * Upsert products by external_id. Each product may carry variations.
     *
     * @return array{created:int, updated:int, items:array<int,array>}
     */
    public function products(int $tenantId, array $products): array
    {
        $results = ['created' => 0, 'updated' => 0, 'items' => []];

        foreach ($products as $p) {
            $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->when(isset($p['external_id']), fn ($q) => $q->where('external_id', $p['external_id']))
                ->first();

            $data = [
                'tenant_id' => $tenantId,
                'name' => $p['name'],
                'size' => $p['size'] ?? null,
                'quantity' => $p['quantity'],
                'unit_price' => $p['unit_price'],
                'description' => $p['description'] ?? null,
            ];
            if (! empty($p['sku'])) {
                $data['sku'] = $p['sku'];
            }
            if (isset($p['external_id'])) {
                $data['external_id'] = $p['external_id'];
            }

            if ($product) {
                $product->update($data);
                $results['updated']++;
                $status = 'updated';
            } else {
                $product = new Product($data);
                $product->tenant_id = $tenantId;
                $product->save();
                $results['created']++;
                $status = 'created';
            }

            $results['items'][] = [
                'id' => $product->id,
                'status' => $status,
                'variations_synced' => $this->variations($tenantId, $product, $p['variations'] ?? []),
            ];
        }

        return $results;
    }

    /** Variants are matched by external_id, the only stable key a store's variant ids give us. */
    private function variations(int $tenantId, Product $product, array $variations): int
    {
        $count = 0;

        foreach ($variations as $v) {
            $variation = ProductVariation::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->when(isset($v['external_id']), fn ($q) => $q->where('external_id', $v['external_id']))
                ->first();

            $data = [
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'name' => $v['name'],
                'sku' => $v['sku'] ?? null,
                'quantity' => $v['quantity'],
                'unit_price' => $v['unit_price'] ?? $product->unit_price,
            ];
            if (isset($v['external_id'])) {
                $data['external_id'] = $v['external_id'];
            }

            $variation ? $variation->update($data) : ProductVariation::create($data);
            $count++;
        }

        return $count;
    }

    /**
     * Record orders as sales.
     *
     * @param  bool  $storeOwnsStock  true for Shopify/WooCommerce: the store already
     *         deducted the stock and we mirror its level through product sync, so
     *         recording the sale must not deduct it a second time (or refuse it
     *         because the store just sold its last unit).
     * @return array{created:int, duplicates:int, errors:array}
     */
    public function orders(int $tenantId, array $orders, bool $storeOwnsStock = false): array
    {
        $results = ['created' => 0, 'duplicates' => 0, 'errors' => []];

        foreach ($orders as $index => $order) {
            $externalId = $order['external_order_id'] ?? null;

            // Stores re-deliver webhooks; an order we've already booked is a no-op.
            if ($externalId && Sale::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_order_id', $externalId)->exists()) {
                $results['duplicates']++;
                continue;
            }

            DB::beginTransaction();
            try {
                foreach ($order['items'] as $item) {
                    $this->bookItem($tenantId, $order, $item, $storeOwnsStock);
                }
                DB::commit();
                $results['created']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['errors'][] = ['order_index' => $index, 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function bookItem(int $tenantId, array $order, array $item, bool $storeOwnsStock): void
    {
        // Neither id given used to fall through to an unfiltered query that
        // matched the tenant's first product — require one explicitly.
        if (empty($item['product_id']) && empty($item['external_product_id'])) {
            throw new \Exception('Order item must include product_id or external_product_id');
        }

        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->when(! empty($item['product_id']),
                fn ($q) => $q->where('id', $item['product_id']),
                fn ($q) => $q->where('external_id', $item['external_product_id']))
            ->first();

        if (! $product) {
            throw new \Exception('Product not found for order item');
        }

        $variationId = $item['variation_id'] ?? null;
        if (! $variationId && ! empty($item['external_variation_id'])) {
            $variation = ProductVariation::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('external_id', $item['external_variation_id'])
                ->first();

            if (! $variation) {
                throw new \Exception('Variation not found for order item');
            }
            $variationId = $variation->id;
        }

        $attrs = [
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'variation_id' => $variationId,
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'notes' => $order['notes'] ?? null,
            'external_order_id' => $order['external_order_id'] ?? null,
            'sale_date' => $order['sale_date'] ?? now(),
        ];

        if ($storeOwnsStock) {
            // Skip the model's stock-deduction hook; set the total ourselves.
            Sale::withoutEvents(fn () => Sale::create($attrs + [
                'total_amount' => $item['quantity'] * $item['unit_price'],
            ]));
        } else {
            Sale::create($attrs);
        }
    }
}
