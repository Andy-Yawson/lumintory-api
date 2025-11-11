<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantSubscription
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (!$user || !$user->tenant) {
            return response()->json(['message' => 'No tenant found', 'success' => false], 404);
        }

        $tenant = $user->tenant;

        if (
            !$tenant->is_active ||
            ($tenant->subscription_ends_at && $tenant->subscription_ends_at < now())
        ) {

            return response()->json([
                'error' => 'Subscription expired',
                'message' => 'Please renew your plan to continue using Lumintory.',
                'expired_at' => Carbon::parse($tenant->subscription_ends_at)->format('Y-m-d'),
                'plan' => $tenant->plan,
                'success' => false
            ], 403);
        }

        return $next($request);
    }
}
