<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Auth;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::orderBy('name', 'asc')->get();

        return response()->json([
            'data' => $categories
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // tenant_id is handled by the Model's boot method
        $category = Category::create($data);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        // Ensure user belongs to the same tenant (failsafe)
        if ($category->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $category->update($data);

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        if ($category->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }

        $category->delete();

        return response()->json(null, 204);
    }

}
