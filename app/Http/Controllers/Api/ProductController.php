<?php

namespace App\Http\Controllers\Api;

use App\Exports\ProductTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\PlanLimit;
use App\Services\ProductImportService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    use \App\Http\Controllers\Concerns\ReadsSpreadsheet;

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');

        $query = Product::with(['category', 'variations', 'images', 'videos'])->where('tenant_id', Auth::user()->tenant_id);

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

            // An empty list means "no variations" (a simple product).
            'variations' => 'nullable|array',
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
        $this->authorizeProduct($product);

        return $product->load(['category', 'variations', 'images', 'videos']);
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

        return DB::transaction(function () use ($product, $data) {

            // variations absent      -> plain field update (quantity as sent)
            // variations: []         -> product becomes simple: variations removed, quantity as sent
            // variations: [...]      -> stock is the sum of the variations; a sent quantity is ignored
            $variationsSent = array_key_exists('variations', $data) && is_array($data['variations']);
            $hasVariations = $variationsSent && count($data['variations']) > 0;

            $fields = collect($data)->except('variations');
            if ($hasVariations) {
                $fields = $fields->except('quantity');
            }
            $product->update($fields->toArray());

            if ($variationsSent && ! $hasVariations) {
                $product->variations()->delete();
            }

            if ($hasVariations) {

                $incomingIds = collect($data['variations'])
                    ->pluck('id')
                    ->filter()
                    ->values();

                $product->variations()
                    ->whereNotIn('id', $incomingIds)
                    ->delete();

                $totalQuantity = 0;

                foreach ($data['variations'] as $variationData) {
                    $totalQuantity += (float) $variationData['quantity'];

                    // FIX: Find the MODEL instance so that $casts are respected
                    if (!empty($variationData['id'])) {
                        // Only this product's own variations can be edited through it.
                        $variation = $product->variations()->whereKey($variationData['id'])->first();
                        if ($variation) {
                            $variation->fill([
                                'name' => $variationData['name'],
                                'quantity' => (float) $variationData['quantity'], // Cast to float explicitly
                                'unit_price' => $variationData['unit_price'] ?? null,
                                'sku' => $variationData['sku'] ?? null,
                            ]);
                            $variation->save();
                        }
                    }
                    // Create new variation via the relation (this uses the model)
                    else {
                        $product->variations()->create([
                            'name' => $variationData['name'],
                            'quantity' => (float) $variationData['quantity'],
                            'unit_price' => $variationData['unit_price'] ?? null,
                            'sku' => $variationData['sku'] ?? null,
                        ]);
                    }
                }

                $product->update([
                    'quantity' => $totalQuantity
                ]);
            }

            if (!$hasVariations && array_key_exists('quantity', $data)) {
                $product->update([
                    'quantity' => (float) $data['quantity']
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
        $paths = $product->images()->pluck('path')->all();
        $product->videos()->get()->each->deleteFiles(); // rows go with the product (cascade), files don't
        $product->delete();
        if ($paths) {
            rescue(fn () => \Illuminate\Support\Facades\Storage::disk('media')->delete($paths), report: true);
        }
        return response()->json(null, 204);
    }

    public function downloadTemplate()
    {
        $fileName = 'product_import_template.xlsx';
        return Excel::download(new ProductTemplateExport, $fileName);
    }

    /**
     * Step 1 of the guided import: read the file and tell the client what's in
     * it — headers, a few sample rows and our best guess at which column is
     * which — so the person can confirm the mapping instead of reshaping
     * their spreadsheet to match ours.
     */
    public function importPreview(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240', 'sheet' => 'nullable|integer|min:0']);

        [$headers, $rows, $book] = $this->readSpreadsheet($request->file('file'), $request->filled('sheet') ? $request->integer('sheet') : null, '/product|stock|inventor|item|catalog/');

        if (empty($headers)) {
            return response()->json(['message' => 'That file looks empty.'], 422);
        }

        return response()->json([
            'headers' => $headers,
            'sample' => array_slice($rows, 0, 5),
            'total_rows' => count($rows),
            'fields' => ProductImportService::fieldCatalogue(),
            'suggested_mapping' => (object) ProductImportService::suggestMapping($headers),
            'row_limit' => PlanLimit::getLimit(Auth::user()->tenant, 'excel_import_rows'),
        ] + $book);
    }

    /** Dry run of import(): same file + mapping, nothing is written; returns what each row would do. */
    public function importDryRun(Request $request, ProductImportService $importer)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'mapping' => 'required|string',
            'duplicates' => 'nullable|in:skip,update',
            'sheet' => 'nullable|integer|min:0',
        ]);

        $tenant = Auth::user()->tenant;
        [, $rows] = $this->readSpreadsheet($request->file('file'), $request->filled('sheet') ? $request->integer('sheet') : null, '/product|stock|inventor|item|catalog/');

        $mapping = array_map('intval', array_filter((array) json_decode($request->input('mapping'), true), fn ($v) => $v !== null && $v !== ''));
        if (! isset($mapping['name'])) {
            return response()->json(['error' => true, 'message' => 'Choose which column holds the product name.'], 422);
        }

        return response()->json($importer->plan(
            $tenant->id,
            PlanLimit::getLimit($tenant, 'products'),
            $rows,
            $mapping,
            $request->input('duplicates', 'skip'),
        ) + ['row_limit' => PlanLimit::getLimit($tenant, 'excel_import_rows')]);
    }

    public function import(Request $request, ProductImportService $importer)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'mapping' => 'nullable|string',
            'duplicates' => 'nullable|in:skip,update',
            'sheet' => 'nullable|integer|min:0',
        ]);

        $tenant = Auth::user()->tenant;
        [$headers, $rows] = $this->readSpreadsheet($request->file('file'), $request->filled('sheet') ? $request->integer('sheet') : null, '/product|stock|inventor|item|catalog/');

        $mapping = $request->filled('mapping')
            ? array_map('intval', array_filter((array) json_decode($request->input('mapping'), true), fn ($v) => $v !== null && $v !== ''))
            : ProductImportService::suggestMapping($headers);

        if (! isset($mapping['name'])) {
            return response()->json(['error' => true, 'message' => 'Choose which column holds the product name.'], 422);
        }

        $rowLimit = PlanLimit::getLimit($tenant, 'excel_import_rows');
        if ($rowLimit !== null && count($rows) > $rowLimit) {
            return response()->json([
                'error' => true,
                'message' => "Your plan allows importing up to {$rowLimit} rows per file. This file has " . count($rows) . '.',
            ], 422);
        }

        $result = $importer->import(
            $tenant->id,
            PlanLimit::getLimit($tenant, 'products'),
            $rows,
            $mapping,
            $request->input('duplicates', 'skip'),
        );

        $parts = array_filter([
            $result['created'] ? "{$result['created']} added" : null,
            $result['updated'] ? "{$result['updated']} updated" : null,
            $result['skipped'] ? "{$result['skipped']} skipped" : null,
            $result['failed'] ? "{$result['failed']} failed" : null,
        ]);

        return response()->json($result + ['message' => $parts ? implode(', ', $parts) : 'Nothing to import']);
    }

    /** @return array{0: array<int,string>, 1: array<int, array<int,mixed>>} headers, data rows */
    public function addStock(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $request->validate([
            'quantity' => 'nullable|numeric|min:0',
            'variations' => 'nullable|array'
        ]);

        try {
            DB::beginTransaction();

            $wasUpdated = false;

            if ($request->has('variations') && !empty($request->variations)) {
                foreach ($request->variations as $vId => $addQty) {

                    if (is_null($addQty) || (float) $addQty <= 0) {
                        continue;
                    }

                    $variation = $product->variations()->where('id', $vId)->first();

                    if ($variation) {
                        // FIX: Use manual addition instead of increment() for decimals
                        $variation->quantity = (float) $variation->quantity + (float) $addQty;
                        $variation->save();
                        $wasUpdated = true;
                    }
                }

                if ($wasUpdated) {
                    $product->syncTotalQuantity();
                }
            } else if ($request->has('quantity') && (float) $request->quantity > 0) {
                // FIX: Manual addition for product too
                $product->quantity = (float) $product->quantity + (float) $request->quantity;
                $product->save();
                $wasUpdated = true;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $wasUpdated ? 'Stock updated successfully' : 'No changes were made',
                'data' => $product->fresh()->load('variations')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Restock failed: ' . $e->getMessage()
            ], 500);
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
