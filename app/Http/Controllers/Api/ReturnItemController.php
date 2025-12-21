<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnItem;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReturnItemController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = ReturnItem::with(['sale.product', 'sale.customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderByDesc('return_date');

        // Date Range Filtering
        if ($startDate && $endDate) {
            $query->whereBetween('return_date', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);
        } elseif ($startDate) {
            $query->whereDate('return_date', '>=', Carbon::parse($startDate));
        } elseif ($endDate) {
            $query->whereDate('return_date', '<=', Carbon::parse($endDate));
        }

        // Search Logic
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                    ->orWhereHas('sale.product', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('sale.customer', fn($q3) => $q3->where('name', 'like', "%{$search}%"));
            });
        }

        $returns = $query->paginate($perPage);

        return response()->json([
            'data' => $returns->items(),
            'current_page' => $returns->currentPage(),
            'last_page' => $returns->lastPage(),
            'total' => $returns->total(),
            'per_page' => $returns->perPage(),
        ]);
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
