<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        if (strtolower($user->role) !== 'superadmin') {
            abort(403, 'You are not authorized to perform this area.');
        }

        return $next($request);
    }
}
