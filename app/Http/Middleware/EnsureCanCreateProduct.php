<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\PlanLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanCreateProduct
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $user->tenant;
        $limit = PlanLimit::getLimit($tenant, 'products');

        if (is_null($limit)) {
            return $next($request);
        }

        $currentCount = Product::where('tenant_id', $tenant->id)->count();

        if ($currentCount >= $limit) {
            return response()->json([
                'message' => "You have reached your product limit ({$limit}) for the {$tenant->plan} plan. Please upgrade to add more products.",
            ], 403);
        }

        return $next($request);
    }
}
