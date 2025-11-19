<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class CheckPlanLimits
{
    public function handle(Request $request, Closure $next, string $resource): mixed
    {
        $user = Auth::user();

        if (!$user || !$user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant found.',
            ], 404);
        }

        $tenant = $user->tenant;
        $plan = $tenant->plan ?? 'basic';

        $limits = config('plan_limits.' . $plan, []);

        // If no limits configured for this resource (or plan is unlimited), allow
        if (!isset($limits[$resource]) || $limits[$resource] === null) {
            return $next($request);
        }

        $maxAllowed = (int) $limits[$resource];

        // Count current for this tenant
        $currentCount = match ($resource) {
            'customers' => Customer::where('tenant_id', $tenant->id)->count(),
            'products' => Product::where('tenant_id', $tenant->id)->count(),
            default => 0,
        };

        if ($currentCount >= $maxAllowed) {
            return response()->json([
                'success' => false,
                'error' => 'plan_limit_reached',
                'message' => "Your {$plan} plan allows only {$maxAllowed} {$resource}. Please upgrade to add more.",
                'plan' => $plan,
                'limits' => [
                    $resource => $maxAllowed,
                ],
            ], 403);
        }

        return $next($request);
    }
}
