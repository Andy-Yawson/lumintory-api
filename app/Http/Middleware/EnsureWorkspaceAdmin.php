<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace-level administration (integrations, API keys, the audit trail, AI access):
 * only a workspace Administrator, or platform SuperAdmin. Sales/Support users get 403.
 */
class EnsureWorkspaceAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->user()?->role, ['Administrator', 'SuperAdmin'], true)) {
            abort(403, 'Only a workspace Administrator can do this.');
        }

        return $next($request);
    }
}
