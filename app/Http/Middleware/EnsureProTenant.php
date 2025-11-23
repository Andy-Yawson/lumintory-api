<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->tenant) {
            abort(403, 'No tenant context');
        }

        if (!in_array(strtolower($user->tenant->plan), ['pro', 'custom'])) {
            abort(403, 'This feature is available for Pro plans only.');
        }

        return $next($request);
    }
}
