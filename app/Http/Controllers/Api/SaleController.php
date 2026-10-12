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
use Illuminate\Validation\Rule;
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
        $tenantId = Auth::user()->tenant_id;
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        // Validate the structure including the 'items' array; every id must belong to this workspace.
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', $inTenant('products')],
            'items.*.variation_id' => ['nullable', $inTenant('product_variations')],
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'customer_id' => ['nullable', $inTenant('customers')],
            'overall_discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'sale_date' => 'nullable|date',
            'payment_method' => 'required|string',
        ]);

        foreach ($request->items as $i => $item) {
            if (! empty($item['variation_id'])
                && ! ProductVariation::whereKey($item['variation_id'])->where('product_id', $item['product_id'])->exists()) {
                return response()->json([
                    'message' => 'That variation does not belong to the selected product.',
                    'errors' => ["items.{$i}.variation_id" => ['That variation does not belong to the selected product.']],
                ], 422);
            }
        }

        try {
            return DB::transaction(function () use ($request) {
                $sales = [];
                $saleDate = $request->sale_date ? Carbon::parse($request->sale_date) : now();
                $tenantId = Auth::user()->tenant_id;

                $overallDiscount = (float) ($request->overall_discount ?? 0);

                // 1. Calculate total gross value to determine proportions
                $totalGross = collect($request->items)->sum(fn($i) => $i['quantity'] * $i['unit_price']);

                foreach ($request->items as $item) {

                    $itemGross = $item['quantity'] * $item['unit_price'];

                    // 2. Pro-rata distribution
                    $itemDiscount = 0;
                    if ($overallDiscount > 0 && $totalGross > 0) {
                        $itemDiscount = round(($itemGross / $totalGross) * $overallDiscount, 2);
                    }

                    try {
                        $sale = Sale::create([
                        'tenant_id' => $tenantId,
                        'product_id' => $item['product_id'],
                        'variation_id' => $item['variation_id'] ?? null,
                        'customer_id' => $request->customer_id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount' => $itemDiscount,
                        'total_amount' => $item['quantity'] * $item['unit_price'],
                        'notes' => $request->notes,
                        'sale_date' => $saleDate,
                        'payment_method' => $request->payment_method,
                        ]);
                    } catch (\Exception $e) {
                        if (! str_starts_with($e->getMessage(), 'Insufficient')) {
                            throw $e;
                        }
                        $name = Product::find($item['product_id'])?->name ?? 'one of the items';
                        throw new \RuntimeException("Insufficient stock: not enough {$name} in stock for this sale.");
                    }
                    $sales[] = $sale;
                }

                return response()->json([
                    'message' => 'Sales recorded successfully',
                    'count' => count($sales),
                    'sales' => $sales
                ], 201);
            });
        } catch (\Exception $e) {
            // Running out of stock is a problem with the request, not the server.
            if (str_starts_with($e->getMessage(), 'Insufficient')) {
                return response()->json(['message' => ucfirst(trim(substr($e->getMessage(), strlen('Insufficient stock:'))))], 422);
            }
            report($e);

            return response()->json(['message' => 'Could not record the sale. Please try again.'], 500);
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

        // Validate BEFORE touching any stock — a failed validation used to
        // leave stock already restored with nothing to reverse it, because
        // there was no transaction and stock mutation happened first.
        $data = $request->validate([
            'quantity' => 'numeric|min:1',
            'unit_price' => 'numeric|min:0',
            'variation_id' => 'nullable|exists:product_variations,id',
            'notes' => 'nullable|string',
            'sale_date' => 'date',
            'payment_method' => 'nullable|string',
        ]);

        $originalVariationId = $sale->variation_id;
        $originalQuantity = $sale->quantity;
        $productId = $sale->product_id;

        $sale = DB::transaction(function () use ($sale, $data, $originalVariationId, $originalQuantity, $productId) {
            // Restore the stock this sale previously held. A variation sale
            // moves both the variation and the aggregate product quantity
            // together (mirrors Sale's own creating()/deleting() hooks) —
            // the old code only ever touched one or the other, so a
            // variation sale's product-level quantity silently drifted out
            // of sync on every edit.
            if ($originalVariationId) {
                ProductVariation::lockForUpdate()->find($originalVariationId)?->increment('quantity', $originalQuantity);
            }
            Product::lockForUpdate()->find($productId)?->increment('quantity', $originalQuantity);

            $sale->fill($data);

            // Deduct the new stock. Wrapped in the same transaction as the
            // restore above, so an insufficient-stock exception here rolls
            // the restore back too instead of leaving stock over-credited.
            if ($sale->variation_id) {
                $variation = ProductVariation::lockForUpdate()->find($sale->variation_id);
                if (!$variation || $variation->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient variation stock');
                }
                $variation->decrement('quantity', $sale->quantity);
            }

            $product = Product::lockForUpdate()->find($sale->product_id);
            if (!$product || $product->quantity < $sale->quantity) {
                throw new \Exception('Insufficient product stock');
            }
            $product->decrement('quantity', $sale->quantity);

            // Preserve the sale's existing discount — recalculating from
            // quantity * unit_price alone silently zeroed it out on every
            // edit of a discounted sale.
            $sale->total_amount = ($sale->quantity * $sale->unit_price) - ($sale->discount ?? 0);
            $sale->save();

            return $sale;
        });

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
        $sale->load('product', 'customer', 'tenant', 'variation');

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
