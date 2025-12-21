<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Sale::with(['product', 'customer'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->orderByDesc('sale_date');

        // Apply Date Range Filter
        if ($startDate && $endDate) {
            $query->whereBetween('sale_date', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay()
            ]);
        } elseif ($startDate) {
            $query->whereDate('sale_date', '>=', Carbon::parse($startDate));
        } elseif ($endDate) {
            $query->whereDate('sale_date', '<=', Carbon::parse($endDate));
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('product', fn($q2) => $q2->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn($q3) => $q3->where('name', 'like', "%{$search}%"))
                    ->orWhere('total_amount', 'like', "%{$search}%");
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


    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'nullable|exists:customers,id',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric',
            'notes' => 'nullable|string',
            'sale_date' => 'nullable|date',
            'variation' => 'nullable|array'
        ]);

        $product = Product::findOrFail($data['product_id']);
        $this->authorizeTenant($product);

        // Override price from variation if provided
        if (!empty($data['variation']['value'])) {
            $data['unit_price'] = $product->getVariationPrice($data['variation']['value']);
        }

        $data['total_amount'] = $data['quantity'] * $data['unit_price'];
        $data['tenant_id'] = Auth::user()->tenant_id;
        $data['sale_date'] = is_null($data["sale_date"]) ? now() : Carbon::parse($data["sale_date"]);

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
