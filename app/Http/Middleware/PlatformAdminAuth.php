<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Server-to-server auth for the Zinnvy Identity admin console. A single shared
 * bearer secret (ZINNVY_PLATFORM_ADMIN_TOKEN); with no secret configured the
 * routes are closed, so a missing env var can never expose them.
 */
class PlatformAdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('services.zinnvy_platform.admin_token');
        $given = (string) $request->bearerToken();

        abort_unless($secret !== '' && $given !== '' && hash_equals($secret, $given), 401, 'Unauthorized.');

        return $next($request);
    }
}
