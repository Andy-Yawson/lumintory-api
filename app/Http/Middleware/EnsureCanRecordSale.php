<?php

namespace App\Http\Middleware;

use App\Models\Sale;
use App\Services\PlanLimit;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanRecordSale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $user->tenant;
        $limit = PlanLimit::getLimit($tenant, 'sales_per_month');

        if (is_null($limit)) {
            return $next($request); // unlimited
        }

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $currentCount = Sale::where('tenant_id', $tenant->id)
            ->whereBetween('sale_date', [$startOfMonth, $endOfMonth])
            ->count();

        if ($currentCount >= $limit) {
            return response()->json([
                'message' => "You have reached your monthly sales limit ({$limit}) for the {$tenant->plan} plan. Please upgrade to record more sales.",
            ], 403);
        }

        return $next($request);
    }
}
