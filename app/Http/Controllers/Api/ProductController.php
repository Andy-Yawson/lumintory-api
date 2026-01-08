<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductsImport;
use App\Models\Product;
use App\Models\ProductVariation;
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

        $query = Product::with(['category', 'variations'])->where('tenant_id', Auth::user()->tenant_id);

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
            'unit_price' => 'required|numeric',
            'quantity' => 'nullable|numeric', // only used when no variations
            'description' => 'nullable|string',
            'lead_time_days' => 'nullable|integer',
            'min_stock_threshold' => 'nullable|integer',
            'category_id' => 'nullable|exists:categories,id',

            'variations' => 'nullable|array|min:1',
            'variations.*.name' => 'required|string',
            'variations.*.quantity' => 'required|numeric|min:0',
            'variations.*.unit_price' => 'nullable|numeric',
            'variations.*.sku' => 'nullable|string',
        ]);

        $tenantId = Auth::user()->tenant_id;

        return DB::transaction(function () use ($data, $tenantId) {

            $hasVariations = !empty($data['variations']);

            // 1. Create product
            $product = Product::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'size' => $data['size'] ?? null,
                'unit_price' => $data['unit_price'],
                'quantity' => $hasVariations
                    ? array_sum(array_column($data['variations'], 'quantity'))
                    : ($data['quantity'] ?? 0),
                'description' => $data['description'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? 7,
                'min_stock_threshold' => $data['min_stock_threshold'] ?? 0,
                'category_id' => $data['category_id'] ?? null,
            ]);

            // 2. Create variations (if any)
            if ($hasVariations) {
                foreach ($data['variations'] as $variation) {
                    $product->variations()->create([
                        'name' => $variation['name'],
                        'quantity' => $variation['quantity'],
                        'unit_price' => $variation['unit_price'] ?? null,
                        'sku' => $variation['sku'] ?? null,
                    ]);
                }
            }

            return response()->json(
                $product->load('variations'),
                201
            );
        });
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

        $data = $request->validate([
            'name' => 'sometimes|string',
            'size' => 'nullable|string',
            'unit_price' => 'sometimes|numeric',
            'quantity' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'lead_time_days' => 'nullable|integer',
            'min_stock_threshold' => 'nullable|integer',
            'category_id' => 'nullable|exists:categories,id',

            'variations' => 'nullable|array',
            'variations.*.id' => 'nullable|exists:product_variations,id',
            'variations.*.name' => 'required|string',
            'variations.*.quantity' => 'required|numeric|min:0',
            'variations.*.unit_price' => 'nullable|numeric',
            'variations.*.sku' => 'nullable|string',
        ]);

        if (isset($data['quantity'], $data['variations'])) {
            return response()->json([
                'message' => 'Cannot update quantity and variations simultaneously.'
            ], 422);
        }

        return DB::transaction(function () use ($product, $data) {

            $hasVariations = array_key_exists('variations', $data);

            // 1. Update product fields
            $product->update(collect($data)->except('variations')->toArray());

            // 2. If variations are present in request
            if ($hasVariations) {

                // Track incoming variation IDs
                $incomingIds = collect($data['variations'])
                    ->pluck('id')
                    ->filter()
                    ->values();

                // Delete removed variations
                $product->variations()
                    ->whereNotIn('id', $incomingIds)
                    ->delete();

                $totalQuantity = 0;

                foreach ($data['variations'] as $variationData) {

                    $totalQuantity += $variationData['quantity'];

                    // Update existing variation
                    if (!empty($variationData['id'])) {
                        $product->variations()
                            ->where('id', $variationData['id'])
                            ->update([
                                'name' => $variationData['name'],
                                'quantity' => $variationData['quantity'],
                                'unit_price' => $variationData['unit_price'] ?? null,
                                'sku' => $variationData['sku'] ?? null,
                            ]);
                    }
                    // Create new variation
                    else {
                        $product->variations()->create([
                            'name' => $variationData['name'],
                            'quantity' => $variationData['quantity'],
                            'unit_price' => $variationData['unit_price'] ?? null,
                            'sku' => $variationData['sku'] ?? null,
                        ]);
                    }
                }

                // Always sync product quantity
                $product->update([
                    'quantity' => $totalQuantity
                ]);
            }

            // 3. No variations → quantity is authoritative
            if (!$hasVariations && array_key_exists('quantity', $data)) {
                $product->update([
                    'quantity' => $data['quantity']
                ]);
            }

            return response()->json(
                $product->fresh()->load('variations')
            );
        });
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

    public function lowStock()
    {
        $tenantId = Auth::user()->tenant_id;

        // 1. Get products that HAVE NO variations and are low on stock
        $lowStockProducts = Product::where('tenant_id', $tenantId)
            ->whereDoesntHave('variations')
            ->where(function ($query) {
                $query->whereRaw('quantity <= min_stock_threshold')
                    ->orWhere(function ($q) {
                        $q->whereNull('min_stock_threshold')
                            ->where('quantity', '<=', 10);
                    });
            })
            ->with('category')
            ->get()
            ->map(function ($product) {
                $product->display_name = $product->name;
                $product->is_variation = false;
                return $product;
            });

        // 2. Get variations that are low on stock
        // We join products to get the tenant's threshold settings
        $lowStockVariations = ProductVariation::where('product_variations.tenant_id', $tenantId)
            ->join('products', 'product_variations.product_id', '=', 'products.id')
            ->where(function ($query) {
                $query->whereRaw('product_variations.quantity <= products.min_stock_threshold')
                    ->orWhere(function ($q) {
                        $q->whereNull('products.min_stock_threshold')
                            ->where('product_variations.quantity', '<=', 10);
                    });
            })
            ->select('product_variations.*', 'products.name as parent_name', 'products.min_stock_threshold as parent_threshold')
            ->with('product.category')
            ->get()
            ->map(function ($variation) {
                $variation->display_name = $variation->parent_name . ' (' . $variation->name . ')';
                $variation->is_variation = true;
                // Map threshold for consistency in frontend
                $variation->min_stock_threshold = $variation->parent_threshold;
                return $variation;
            });

        // 3. Merge and sort by quantity
        $combined = $lowStockProducts->concat($lowStockVariations)
            ->sortBy('quantity')
            ->values();

        return response()->json([
            'data' => $combined,
            'count' => $combined->count()
        ]);
    }

    protected function authorizeProduct(Product $product)
    {
        if ($product->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }
}
