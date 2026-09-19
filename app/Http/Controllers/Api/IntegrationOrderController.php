<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use DB;
use Illuminate\Http\Request;

class IntegrationOrderController extends Controller
{
    public function sync(Request $request)
    {
        $tenant = $request->attributes->get('integration_tenant');

        $data = $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*.external_order_id' => 'nullable|string|max:255',
            'orders.*.notes' => 'nullable|string',
            'orders.*.sale_date' => 'nullable|date',
            'orders.*.items' => 'required|array|min:1',
            'orders.*.items.*.product_id' => 'nullable|integer|exists:products,id',
            'orders.*.items.*.external_product_id' => 'nullable|string|max:255',
            'orders.*.items.*.quantity' => 'required|integer|min:1',
            'orders.*.items.*.unit_price' => 'required|numeric|min:0',
            'orders.*.items.*.variation_id' => 'nullable|integer|exists:product_variations,id',
            'orders.*.items.*.external_variation_id' => 'nullable|string|max:255',
        ]);

        $results = [
            'created' => 0,
            'errors' => [],
        ];

        foreach ($data['orders'] as $index => $orderPayload) {
            DB::beginTransaction();

            try {
                foreach ($orderPayload['items'] as $item) {
                    // Resolve product either by product_id or external_product_id.
                    // Neither given used to fall through to an unfiltered
                    // query, silently matching the tenant's first product —
                    // require one explicitly instead.
                    if (empty($item['product_id']) && empty($item['external_product_id'])) {
                        throw new \Exception('Order item must include product_id or external_product_id');
                    }

                    $productQuery = Product::where('tenant_id', $tenant->id);

                    if (! empty($item['product_id'])) {
                        $productQuery->where('id', $item['product_id']);
                    } else {
                        $productQuery->where('external_id', $item['external_product_id']);
                    }

                    $product = $productQuery->first();

                    if (! $product) {
                        throw new \Exception('Product not found for order item');
                    }

                    // Resolve either a direct variation_id or an
                    // external_variation_id from the source platform to a
                    // real ProductVariation row — Sale's own creating()
                    // hook moves both the variation's and the product's
                    // stock together once variation_id is set correctly.
                    $variationId = $item['variation_id'] ?? null;

                    if (! $variationId && ! empty($item['external_variation_id'])) {
                        $variation = ProductVariation::where('tenant_id', $tenant->id)
                            ->where('product_id', $product->id)
                            ->where('external_id', $item['external_variation_id'])
                            ->first();

                        if (! $variation) {
                            throw new \Exception('Variation not found for order item');
                        }

                        $variationId = $variation->id;
                    }

                    Sale::create([
                        'tenant_id' => $tenant->id,
                        'product_id' => $product->id,
                        'variation_id' => $variationId,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'notes' => $orderPayload['notes'] ?? null,
                        'sale_date' => $orderPayload['sale_date'] ?? now(),
                    ]);
                }

                DB::commit();
                $results['created']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['errors'][] = [
                    'order_index' => $index,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json($results);
    }
}
