<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Integrations\CatalogSync;
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

    public function sync(Request $request, CatalogSync $sync)
    {
        $tenant = $request->attributes->get('integration_tenant');

        $payload = $request->validate([
            'products' => 'required|array|min:1',
            'products.*.external_id' => 'nullable|string|max:255',
            'products.*.name' => 'required|string|max:255',
            'products.*.size' => 'nullable|string|max:255',
            'products.*.sku' => 'nullable|string|max:255',
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

        return response()->json($sync->products($tenant->id, $payload['products']));
    }
}
