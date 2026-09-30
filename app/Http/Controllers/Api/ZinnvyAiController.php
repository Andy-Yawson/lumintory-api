<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Read-only endpoints consumed by Zinnvy AI (P4-B).
 *
 * All routes are protected by the integration.auth middleware (ai:read scope).
 * The tenant is resolved by the middleware and injected via request attributes,
 * so none of these methods touch the authenticated user — the caller is a
 * service account, not a person.
 */
class ZinnvyAiController extends Controller
{
    /** GET /v1/integrations/ai/ping — lightweight connectivity check. */
    public function ping(Request $request)
    {
        $tenant = $request->attributes->get('integration_tenant');

        return response()->json([
            'ok' => true,
            'tenant' => $tenant->name,
        ]);
    }

    /**
     * GET /v1/integrations/ai/stock?sku=
     * Returns current stock for a single product identified by SKU or name.
     */
    public function stock(Request $request)
    {
        $request->validate(['sku' => 'required|string|max:255']);

        $tenant = $request->attributes->get('integration_tenant');
        $term = $request->query('sku');

        $product = Product::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($term) {
                $q->where('sku', $term)
                    ->orWhere('name', 'like', "%{$term}%");
            })
            ->with('variations:id,product_id,name,sku,quantity,unit_price')
            ->first();

        if (!$product) {
            return response()->json(['error' => "No product found matching '{$term}'."], 404);
        }

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'quantity' => $product->computed_quantity ?? $product->quantity,
            'unit_price' => $product->unit_price,
            'min_stock_threshold' => $product->min_stock_threshold,
            'variations' => $product->variations->map(fn ($v) => [
                'name' => $v->name,
                'sku' => $v->sku,
                'quantity' => $v->quantity,
                'unit_price' => $v->unit_price,
            ]),
        ]);
    }

    /**
     * GET /v1/integrations/ai/products/search?q=&limit=
     * Full-text search across product name, SKU, size and category.
     */
    public function searchProducts(Request $request)
    {
        $request->validate(['q' => 'required|string|min:1|max:255', 'limit' => 'integer|min:1|max:50']);

        $tenant = $request->attributes->get('integration_tenant');
        $q = $request->query('q');
        $limit = (int) $request->query('limit', 20);

        $products = Product::where('tenant_id', $tenant->id)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('size', 'like', "%{$q}%")
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            })
            ->with('category:id,name')
            ->select('id', 'name', 'sku', 'size', 'quantity', 'unit_price', 'category_id', 'min_stock_threshold')
            ->limit($limit)
            ->get();

        return response()->json([
            'query' => $q,
            'count' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'size' => $p->size,
                'quantity' => $p->quantity,
                'unit_price' => $p->unit_price,
                'category' => $p->category?->name,
                'low_stock' => $p->min_stock_threshold && $p->quantity <= $p->min_stock_threshold,
            ]),
        ]);
    }

    /**
     * GET /v1/integrations/ai/reports/low-stock?threshold=
     * Products at or below their minimum stock threshold (or the given threshold).
     */
    public function lowStock(Request $request)
    {
        $request->validate(['threshold' => 'nullable|integer|min:0']);

        $tenant = $request->attributes->get('integration_tenant');
        $threshold = $request->query('threshold');

        $query = Product::where('tenant_id', $tenant->id)
            ->with('category:id,name');

        if ($threshold !== null) {
            $query->where('quantity', '<=', (int) $threshold);
        } else {
            // Only include products that have a threshold set; compare against it
            $query->whereNotNull('min_stock_threshold')
                ->whereColumn('quantity', '<=', 'min_stock_threshold');
        }

        $products = $query->orderBy('quantity')->get();

        return response()->json([
            'threshold_used' => $threshold,
            'count' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'quantity' => $p->quantity,
                'min_stock_threshold' => $p->min_stock_threshold,
                'category' => $p->category?->name,
                'unit_price' => $p->unit_price,
            ]),
        ]);
    }

    /**
     * GET /v1/integrations/ai/reports/slow-movers?days=
     * Products with no sales in the last N days (default 30).
     */
    public function slowMovers(Request $request)
    {
        $request->validate(['days' => 'nullable|integer|min:1|max:365']);

        $tenant = $request->attributes->get('integration_tenant');
        $days = (int) $request->query('days', 30);
        $since = Carbon::now()->subDays($days);

        $soldIds = Sale::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('product_id');

        $products = Product::where('tenant_id', $tenant->id)
            ->whereNotIn('id', $soldIds)
            ->where('quantity', '>', 0)
            ->with('category:id,name')
            ->orderByDesc('quantity')
            ->get();

        return response()->json([
            'days' => $days,
            'since' => $since->toDateString(),
            'count' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'quantity' => $p->quantity,
                'unit_price' => $p->unit_price,
                'category' => $p->category?->name,
            ]),
        ]);
    }

    /**
     * GET /v1/integrations/ai/reports/sales-summary?from=&to=
     * Aggregate sales figures, optionally within a date range.
     */
    public function salesSummary(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $tenant = $request->attributes->get('integration_tenant');

        $query = Sale::where('tenant_id', $tenant->id);

        $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
        $to = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : null;

        if ($from) $query->where('sale_date', '>=', $from);
        if ($to) $query->where('sale_date', '<=', $to);

        $total = (clone $query)->sum('total_amount');
        $count = (clone $query)->count();
        $units = (clone $query)->sum('quantity');

        $topProducts = (clone $query)
            ->with('product:id,name')
            ->selectRaw('product_id, SUM(total_amount) as revenue, SUM(quantity) as units_sold')
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'product' => $row->product?->name ?? 'Unknown',
                'revenue' => round((float) $row->revenue, 2),
                'units_sold' => (int) $row->units_sold,
            ]);

        return response()->json([
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'total_revenue' => round((float) $total, 2),
            'total_sales' => $count,
            'total_units' => round((float) $units, 2),
            'top_products' => $topProducts,
        ]);
    }

    /**
     * GET /v1/integrations/ai/customers/purchases?customer=&limit=
     * Recent purchase history for a customer identified by name or phone.
     */
    public function customerPurchases(Request $request)
    {
        $request->validate([
            'customer' => 'required|string|max:255',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $tenant = $request->attributes->get('integration_tenant');
        $term = $request->query('customer');
        $limit = (int) $request->query('limit', 20);

        $customer = Customer::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            })
            ->first();

        if (!$customer) {
            return response()->json(['error' => "No customer found matching '{$term}'."], 404);
        }

        $sales = Sale::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->with('product:id,name')
            ->orderByDesc('sale_date')
            ->limit($limit)
            ->get();

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'total_spent' => $customer->total_spent,
            ],
            'purchases_count' => $sales->count(),
            'purchases' => $sales->map(fn ($s) => [
                'date' => $s->sale_date?->toDateString() ?? $s->created_at->toDateString(),
                'product' => $s->product?->name ?? 'Unknown',
                'quantity' => $s->quantity,
                'total_amount' => $s->total_amount,
                'payment_method' => $s->payment_method,
            ]),
        ]);
    }
}
