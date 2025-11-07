<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SaleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $search = $request->get('search');

        $query = Sale::with(['product', 'customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderByDesc('sale_date');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('customer', function ($q3) use ($search) {
                        $q3->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $sales = $query->paginate($perPage);

        return response()->json([
            'data' => $sales->items(),
            'current_page' => $sales->currentPage(),
            'last_page' => $sales->lastPage(),
            'total' => $sales->total(),
            'per_page' => $sales->perPage(),
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'nullable|exists:customers,id',
            'color' => 'nullable|string',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric',
            'notes' => 'nullable|string',
            // 'sale_date' => 'required|date',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $this->authorizeTenant($product);

        // Override price from variation if color provided
        if ($data['color']) {
            $data['unit_price'] = $product->getVariationPrice($data['color']);
        }

        $data['total_amount'] = $data['quantity'] * $data['unit_price'];
        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['sale_date'] = now();

        $sale = Sale::create($data);

        return response()->json($sale->load('product', 'customer'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Sale $sale)
    {
        $this->authorizeTenant($sale);
        return $sale->load('product', 'customer');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Sale $sale)
    {
        $this->authorizeTenant($sale);

        // Restore original stock
        $sale->product->increment('quantity', $sale->quantity);

        $data = $request->validate([
            'quantity' => 'integer|min:1',
            'unit_price' => 'numeric',
            'notes' => 'nullable|string',
            'sale_date' => 'date',
        ]);

        $sale->update($data);
        $sale->total_amount = $sale->quantity * $sale->unit_price;
        $sale->save();

        // Deduct new stock
        $sale->product->decrement('quantity', $sale->quantity);

        return $sale;
    }

    public function destroy(Sale $sale)
    {
        $this->authorizeTenant($sale);
        $sale->product->increment('quantity', $sale->quantity); // Restore
        $sale->delete();
        return response()->json(null, 204);
    }

    public function receipt(Sale $sale)
    {
        $this->authorizeTenant($sale);
        $sale->load('product', 'customer', 'tenant');

        $pdf = Pdf::loadView('receipts.sale', compact('sale'))
            ->setPaper('a5', 'portrait');

        return $pdf->stream("receipt-{$sale->id}.pdf"); // Or download()
    }

    protected function authorizeTenant($model)
    {
        if ($model->tenant_id !== Auth::user()->tenant_id) {
            abort(403, 'Unauthorized');
        }
    }
}
