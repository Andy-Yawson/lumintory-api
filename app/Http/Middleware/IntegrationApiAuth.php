<?php

namespace App\Http\Middleware;

use App\Models\IntegrationApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntegrationApiAuth
{
    public function handle(Request $request, Closure $next, ...$requiredScopes): Response
    {
        $key = $request->header('X-Integration-Key');

        if (!$key) {
            return response()->json(['message' => 'Missing API key'], 401);
        }

        $integrationKey = IntegrationApiKey::where('public_key', $key)
            ->where('is_active', true)
            ->with('tenant')
            ->first();

        if (!$integrationKey || !$integrationKey->tenant || !$integrationKey->tenant->is_active) {
            return response()->json(['message' => 'Invalid or inactive API key'], 401);
        }

        // Check scopes if specified on route
        if (!empty($requiredScopes)) {
            $scopes = $integrationKey->scopes ?? [];
            foreach ($requiredScopes as $scope) {
                if (!in_array($scope, $scopes, true)) {
                    return response()->json(['message' => 'Insufficient scope'], 403);
                }
            }
        }

        // Attach tenant to request for downstream usage
        $request->attributes->set('integration_tenant', $integrationKey->tenant);
        $request->attributes->set('integration_key', $integrationKey);

        $integrationKey->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
