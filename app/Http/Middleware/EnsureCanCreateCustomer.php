<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Services\PlanLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanCreateCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $user->tenant;
        $limit = PlanLimit::getLimit($tenant, 'customers');

        // null = unlimited
        if (is_null($limit)) {
            return $next($request);
        }

        $currentCount = Customer::where('tenant_id', $tenant->id)->count();

        if ($currentCount >= $limit) {
            return response()->json([
                'message' => "You have reached your customer limit ({$limit}) for the {$tenant->plan} plan. Please upgrade to add more customers.",
            ], 403);
        }

        return $next($request);
    }
}
