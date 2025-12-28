<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use App\Models\Product;
use App\Services\PlanLimit;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');

        $query = Product::with('category')->where('tenant_id', Auth::user()->tenant_id);

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('size', 'like', "%{$search}%")
                ->orWhereHas('category', function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%");
                });
        }

        $products = $query->paginate($perPage);

        return response()->json([
            'data' => $products->items(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'total' => $products->total(),
            'per_page' => $products->perPage(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'size' => 'nullable|string',
            'quantity' => 'required|integer',
            'unit_price' => 'required|numeric',
            'variations' => 'nullable|array',
            'description' => 'nullable|string',
            'lead_time_days' => 'nullable|integer',
            'min_stock_threshold' => 'nullable|integer',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $data['tenant_id'] = Auth::user()->tenant_id;

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        return $product;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $this->authorizeProduct($product);

        $product->update($request->validate([
            'name' => 'string',
            'size' => 'nullable|string',
            'quantity' => 'integer',
            'unit_price' => 'numeric',
            'variations' => 'nullable|array',
            'description' => 'nullable|string',
            'lead_time_days' => 'nullable|integer',
            'min_stock_threshold' => 'nullable|integer',
            'category_id' => 'nullable|exists:categories,id',
        ]));

        return $product;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $this->authorizeProduct($product);
        $product->delete();
        return response()->json(null, 204);
    }

    public function downloadTemplate()
    {
        $fileName = 'product_import_template.xlsx';
        return Excel::download(new ProductTemplateExport, $fileName);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);

        $tenantId = Auth::user()->tenant_id;

        $limit = PlanLimit::getLimit(Auth::user()->tenant, 'products') ?? null;

        if ($limit !== null) {
            // Quick pre-read to count rows (excluding heading row)
            $rowCount = Excel::toCollection(null, $request->file('file'))[0]
                ->skip(1) // skip heading row
                ->count();

            if ($rowCount > $limit) {
                return response()->json([
                    'error' => true,
                    'message' => "Your plan allows importing a maximum of {$limit} rows per file. You tried to import {$rowCount} rows.",
                ], 422);
            }
        }


        if ($limit !== null) {
            $currentCount = Product::where('tenant_id', $tenantId)->count();

            if ($currentCount >= $limit) {
                return response()->json([
                    'error' => true,
                    'message' => "You have reached your product limit ({$limit}) for your current plan. Please delete some products or upgrade your plan.",
                ], 422);
            }
        }

        // Let’s pass tenant id into the import class
        Excel::import(new ProductsImport($tenantId, $limit), $request->file('file'));

        return response()->json([
            'message' => 'Products imported successfully',
        ]);
    }

    public function addStock(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($request, $id) {
                // Find product (Global Scope handles tenant_id check)
                $product = Product::findOrFail($id);

                $oldQuantity = $product->quantity;
                $product->increment('quantity', $request->quantity);

                return response()->json([
                    'message' => 'Stock updated successfully',
                    'product_id' => $product->id,
                    'new_quantity' => $product->quantity,
                    'added' => $request->quantity
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update stock'], 500);
        }
    }

    protected function authorizeProduct(Product $product)
    {
        if ($product->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }
}
