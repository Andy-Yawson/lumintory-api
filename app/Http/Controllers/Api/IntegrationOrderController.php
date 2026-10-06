<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Integrations\CatalogSync;
use Illuminate\Http\Request;

class IntegrationOrderController extends Controller
{
    public function sync(Request $request, CatalogSync $sync)
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

        return response()->json($sync->orders($tenant->id, $data['orders']));
    }
}
