<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Http\Request;

class IntegrationProductController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('integration_tenant');

        $products = Product::where('tenant_id', $tenant->id)
            ->select('id', 'external_id', 'name', 'size', 'quantity', 'unit_price', 'description')
            ->with('variations:id,product_id,external_id,name,sku,quantity,unit_price')
            ->paginate(100);

        return response()->json([
            'data' => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
            'per_page' => $products->perPage(),
        ]);
    }

    public function sync(Request $request)
    {
        $tenant = $request->attributes->get('integration_tenant');

        $payload = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.external_id' => 'nullable|string|max:255',
            'products.*.name' => 'required|string|max:255',
            'products.*.size' => 'nullable|string|max:255',
            'products.*.quantity' => 'required|integer|min:0',
            'products.*.unit_price' => 'required|numeric|min:0',
            'products.*.description' => 'nullable|string',
            'products.*.variations' => 'nullable|array',
            'products.*.variations.*.external_id' => 'nullable|string|max:255',
            'products.*.variations.*.name' => 'required_with:products.*.variations|string|max:255',
            'products.*.variations.*.sku' => 'nullable|string|max:255',
            'products.*.variations.*.quantity' => 'required_with:products.*.variations|numeric|min:0',
            'products.*.variations.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $results = [
            'created' => 0,
            'updated' => 0,
            'items' => [],
        ];

        foreach ($payload['products'] as $p) {
            $product = Product::where('tenant_id', $tenant->id)
                ->when(isset($p['external_id']), function ($q) use ($p) {
                    $q->where('external_id', $p['external_id']);
                })
                ->first();

            $data = [
                'tenant_id' => $tenant->id,
                'name' => $p['name'],
                'size' => $p['size'] ?? null,
                'quantity' => $p['quantity'],
                'unit_price' => $p['unit_price'],
                'description' => $p['description'] ?? null,
            ];

            if (isset($p['external_id'])) {
                $data['external_id'] = $p['external_id'];
            }

            if ($product) {
                $product->update($data);
                $results['updated']++;
                $status = 'updated';
            } else {
                $product = Product::create($data);
                $results['created']++;
                $status = 'created';
            }

            $variationsSynced = $this->syncVariations($tenant->id, $product, $p['variations'] ?? []);

            $results['items'][] = [
                'id' => $product->id,
                'status' => $status,
                'variations_synced' => $variationsSynced,
            ];
        }

        return response()->json($results);
    }

    /**
     * Upserts a product's variants the same way the product itself is
     * upserted — matched by external_id when the source system provides
     * one, since that's the only stable key an e-commerce platform's
     * variant IDs give us across repeated syncs.
     */
    private function syncVariations(int $tenantId, Product $product, array $variations): int
    {
        $count = 0;

        foreach ($variations as $v) {
            $variation = ProductVariation::where('tenant_id', $tenantId)
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
}
