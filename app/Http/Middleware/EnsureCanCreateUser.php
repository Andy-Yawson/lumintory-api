<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PlanLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanCreateUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $authUser = $request->user();

        if (!$authUser || !$authUser->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $authUser->tenant;
        $limit = PlanLimit::getLimit($tenant, 'users');

        if (is_null($limit)) {
            return $next($request);
        }

        $currentCount = User::where('tenant_id', $tenant->id)->count();

        if ($currentCount >= $limit) {
            return response()->json([
                'message' => "You have reached your user limit ({$limit}) for the {$tenant->plan} plan.",
            ], 403);
        }

        return $next($request);
    }
}
