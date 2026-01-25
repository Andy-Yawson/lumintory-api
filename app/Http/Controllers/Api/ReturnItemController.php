<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReturnItem;
use App\Models\Sale;
use Carbon\Carbon;
use DB;
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
                    ->orWhere('refund_method', 'like', "%{$search}%")
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
        // Support both single return object or bulk returns array
        $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.sale_id' => 'required|exists:sales,id',
            'returns.*.quantity' => 'required|numeric|min:0.01',
            'returns.*.refund_amount' => 'nullable|numeric',
            'returns.*.reason' => 'required|string',
            'returns.*.return_date' => 'required|date',
            'returns.*.refund_method' => 'required|string',
        ]);

        $processedReturns = [];
        $tenantId = Auth::user()->tenant_id;

        try {
            DB::transaction(function () use ($request, $tenantId, &$processedReturns) {
                foreach ($request->returns as $returnDetail) {
                    $sale = Sale::findOrFail($returnDetail['sale_id']);

                    // Security check
                    if ($sale->tenant_id !== $tenantId) {
                        throw new \Exception("Unauthorized access to sale record.");
                    }

                    // Check availability
                    $alreadyReturned = ReturnItem::where('sale_id', $sale->id)->sum('quantity');
                    $remainingReturnable = $sale->quantity - $alreadyReturned;

                    if ($returnDetail['quantity'] > $remainingReturnable) {
                        throw new \Exception("Item '{$sale->product->name}': Cannot return {$returnDetail['quantity']}. Only {$remainingReturnable} remaining.");
                    }

                    // Auto-calculate refund if null
                    // $refundAmount = $returnDetail['refund_amount'] ?? ($returnDetail['quantity'] * $sale->unit_price);
                    $refundAmount = ($returnDetail['quantity'] * $sale->unit_price);

                    $returnRecord = ReturnItem::create([
                        'tenant_id' => $tenantId,
                        'sale_id' => $sale->id,
                        'product_id' => $sale->product_id,
                        'variation_id' => $sale->variation_id,
                        'customer_id' => $sale->customer_id,
                        'quantity' => $returnDetail['quantity'],
                        'refund_amount' => $refundAmount,
                        'reason' => $returnDetail['reason'],
                        'return_date' => $returnDetail['return_date'],
                        'refund_method' => $returnDetail['refund_method'],
                    ]);

                    $sale->product->increment('quantity', $returnDetail['quantity']);
                    // $sale->delete();

                    $processedReturns[] = $returnRecord;
                }
            });

            return response()->json([
                'success' => true,
                'message' => count($processedReturns) . ' return(s) processed successfully.',
                'data' => $processedReturns
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
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
