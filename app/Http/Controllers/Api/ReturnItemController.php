<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnItem;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReturnItemController extends Controller
{
    public function index()
    {
        return ReturnItem::with(['sale.product', 'sale.customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderByDesc('return_date')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'required|exists:sales,id',
            'quantity' => 'required|integer|min:1',
            'refund_amount' => 'required|numeric',
            'reason' => 'required|string',
            'return_date' => 'required|date',
        ]);

        $sale = Sale::findOrFail($data['sale_id']);
        $this->authorizeTenant($sale);

        if ($data['quantity'] > $sale->quantity) {
            return response()->json(['error' => 'Return quantity exceeds sale'], 422);
        }

        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['product_id'] = $sale->product_id;
        $data['variation'] = $sale->variation;
        $data['customer_id'] = $sale->customer_id;

        $return = ReturnItem::create($data);

        return response()->json($return->load('sale'), 201);
    }

    public function show(ReturnItem $returnItem)
    {
        $this->authorizeTenant($returnItem);
        return $returnItem->load('sale.product', 'sale.customer');
    }

    public function destroy(ReturnItem $returnItem)
    {
        $this->authorizeTenant($returnItem);
        $returnItem->product->decrement('quantity', $returnItem->quantity); // Remove from stock
        $returnItem->delete();
        return response()->json(null, 204);
    }

    protected function authorizeTenant($model)
    {
        if ($model->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }
}
