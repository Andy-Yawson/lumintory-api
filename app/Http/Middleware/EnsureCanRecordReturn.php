<?php

namespace App\Http\Middleware;

use App\Models\ReturnItem;
use App\Services\PlanLimit;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanRecordReturn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $user->tenant;
        $limit = PlanLimit::getLimit($tenant, 'returns_per_month');

        if (is_null($limit)) {
            return $next($request);
        }

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $currentCount = ReturnItem::where('tenant_id', $tenant->id)
            ->whereBetween('return_date', [$startOfMonth, $endOfMonth])
            ->count();

        if ($currentCount >= $limit) {
            return response()->json([
                'message' => "You have reached your monthly returns limit ({$limit}) for the {$tenant->plan} plan.",
            ], 403);
        }

        return $next($request);
    }
}
