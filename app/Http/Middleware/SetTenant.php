<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->tenant) {
            // Set tenant in config or session for queries
            config(['current_tenant_id' => Auth::user()->tenant_id]);
        }

        return $next($request);
    }
}
