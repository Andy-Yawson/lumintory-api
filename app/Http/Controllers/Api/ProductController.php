<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');

        $query = Product::where('tenant_id', Auth::user()->tenant_id);

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('size', 'like', "%{$search}%");
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


    protected function authorizeProduct(Product $product)
    {
        if ($product->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }
}
