<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated workspace is locked out immediately, including people who are already signed in
 * (login already refuses new sessions). Only `is_active` is enforced here: subscription expiry is
 * a separate billing decision and is not used to lock anyone out.
 */
class EnsureWorkspaceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role !== 'SuperAdmin' && ! $user->tenant?->is_active) {
            return response()->json([
                'code' => 'workspace_inactive',
                'message' => 'This workspace has been deactivated. Please contact Zinnvy support.',
            ], 403);
        }

        return $next($request);
    }
}
