<?php

namespace App\Http\Middleware;

use App\Services\PlanLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey, ?string $message = null): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context.');
        }

        $tenant = $user->tenant;

        if (!PlanLimit::hasFeature($tenant, $featureKey)) {
            $msg = $message ?: "This feature ({$featureKey}) is not available on your current plan ({$tenant->plan}). Please upgrade.";
            return response()->json([
                'message' => $msg,
            ], 403);
        }

        return $next($request);
    }
}
