<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
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
            'orders.*.items.*.variation' => 'nullable|array',
        ]);

        $results = [
            'created' => 0,
            'errors' => [],
        ];

        foreach ($data['orders'] as $index => $orderPayload) {
            DB::beginTransaction();

            try {
                foreach ($orderPayload['items'] as $item) {
                    // Resolve product either by product_id or external_product_id
                    $productQuery = Product::where('tenant_id', $tenant->id);

                    if (!empty($item['product_id'])) {
                        $productQuery->where('id', $item['product_id']);
                    } elseif (!empty($item['external_product_id'])) {
                        $productQuery->where('external_id', $item['external_product_id']);
                    }

                    $product = $productQuery->first();

                    if (!$product) {
                        throw new \Exception('Product not found for order item');
                    }

                    $sale = Sale::create([
                        'tenant_id' => $tenant->id,
                        'product_id' => $product->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_amount' => $item['quantity'] * $item['unit_price'],
                        'notes' => $orderPayload['notes'] ?? null,
                        'sale_date' => $orderPayload['sale_date'] ?? now(),
                        'variation' => $item['variation'] ?? null,
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
