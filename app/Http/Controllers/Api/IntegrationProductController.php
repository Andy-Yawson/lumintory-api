<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class IntegrationProductController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('integration_tenant');

        $products = Product::where('tenant_id', $tenant->id)
            ->select('id', 'name', 'size', 'quantity', 'unit_price', 'variations', 'description')
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
            'products.*.variations' => 'nullable|array',
            'products.*.description' => 'nullable|string',
        ]);

        $results = [
            'created' => 0,
            'updated' => 0,
            'items' => [],
        ];

        foreach ($payload['products'] as $p) {
            // We can optionally track an external_id column on Product to map them
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
                'variations' => $p['variations'] ?? null,
                'description' => $p['description'] ?? null,
            ];

            if ($product) {
                $product->update($data);
                $results['updated']++;
                $results['items'][] = [
                    'id' => $product->id,
                    'status' => 'updated',
                ];
            } else {
                if (isset($p['external_id'])) {
                    $data['external_id'] = $p['external_id'];
                }
                $product = Product::create($data);
                $results['created']++;
                $results['items'][] = [
                    'id' => $product->id,
                    'status' => 'created',
                ];
            }
        }

        return response()->json($results);
    }
}
