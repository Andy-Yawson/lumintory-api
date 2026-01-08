<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use DB;
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

        $query = Sale::with(['product', 'customer', 'variation'])
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
                    ->orWhere('payment_method', 'like', "%{$search}%")
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
        // Validate the structure including the new 'items' array
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'customer_id' => 'nullable|exists:customers,id',
            'notes' => 'nullable|string',
            'sale_date' => 'nullable|date',
            'payment_method' => 'required|string',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $sales = [];
                $saleDate = $request->sale_date ? Carbon::parse($request->sale_date) : now();
                $tenantId = Auth::user()->tenant_id;

                foreach ($request->items as $item) {
                    $sales[] = Sale::create([
                        'tenant_id' => $tenantId,
                        'product_id' => $item['product_id'],
                        'variation_id' => $item['variation_id'] ?? null,
                        'customer_id' => $request->customer_id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_amount' => $item['quantity'] * $item['unit_price'],
                        'notes' => $request->notes,
                        'sale_date' => $saleDate,
                        'payment_method' => $request->payment_method,
                    ]);
                }

                return response()->json([
                    'message' => 'Sales recorded successfully',
                    'count' => count($sales),
                    'sales' => $sales
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to record sales: ' . $e->getMessage()], 500);
        }
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

        // Restore original stock before updating
        if ($sale->variation_id) {
            $variation = ProductVariation::lockForUpdate()->find($sale->variation_id);
            $variation?->increment('quantity', $sale->quantity);
        } else {
            $product = Product::lockForUpdate()->find($sale->product_id);
            $product?->increment('quantity', $sale->quantity);
        }

        // Validate incoming data
        $data = $request->validate([
            'quantity' => 'numeric|min:1',
            'unit_price' => 'numeric|min:0',
            'variation_id' => 'nullable|exists:product_variations,id',
            'notes' => 'nullable|string',
            'sale_date' => 'date',
            'payment_method' => 'nullable|string',
        ]);

        // Update the sale record
        $sale->update($data);

        // Recalculate total
        $sale->total_amount = $sale->quantity * $sale->unit_price;
        $sale->save();

        // Deduct new stock after update
        if ($sale->variation_id) {
            $variation = ProductVariation::lockForUpdate()->find($sale->variation_id);
            if (!$variation || $variation->quantity < $sale->quantity) {
                throw new \Exception('Insufficient variation stock');
            }
            $variation->decrement('quantity', $sale->quantity);
        } else {
            $product = Product::lockForUpdate()->find($sale->product_id);
            if (!$product || $product->quantity < $sale->quantity) {
                throw new \Exception('Insufficient product stock');
            }
            $product->decrement('quantity', $sale->quantity);
        }

        return $sale->load('product', 'variation', 'customer');
    }


    public function destroy(Sale $sale)
    {
        $this->authorizeTenant($sale);

        // Restore stock
        if ($sale->variation_id) {
            $variation = ProductVariation::find($sale->variation_id);
            $variation?->increment('quantity', $sale->quantity);
        } else {
            $product = Product::find($sale->product_id);
            $product?->increment('quantity', $sale->quantity);
        }

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
