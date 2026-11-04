<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * For the admin console (admin.zinnvy.com), which signs in with Zinnvy Identity, not with this app. Staff are decided by
 * Identity: the caller's own token is forwarded to Identity's staff-only /api/admin/me and only a 200 means staff.
 * Changes need a superadmin.
 */
class AuthenticateIdentityStaff
{
    public function handle(Request $request, Closure $next, string $level = 'staff')
    {
        $token = $request->bearerToken();
        $staff = $token ? $this->lookup($token) : null;

        if (! $staff) {
            return response()->json(['message' => 'Staff sign-in required.'], 401);
        }
        if ($level === 'superadmin' && empty($staff['is_superadmin'])) {
            return response()->json(['message' => 'Only a superadmin can do this.'], 403);
        }
        $request->attributes->set('staff', $staff);

        return $next($request);
    }

    private function lookup(string $token): ?array
    {
        return Cache::remember('inventory:staff:'.hash('sha256', $token), 60, function () use ($token) {
            try {
                $r = Http::baseUrl(rtrim((string) config('zinnvy.accounts_url'), '/'))->acceptJson()->timeout(8)->withToken($token)->get('/api/admin/me');
            } catch (ConnectionException) {
                return null;
            }

            return $r->successful() && $r->json('id') ? $r->json() : null;
        });
    }
}
