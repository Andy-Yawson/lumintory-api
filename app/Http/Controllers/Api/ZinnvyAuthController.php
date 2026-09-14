<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Zinnvy" — this app acting as an OAuth *client* against
 * Zinnvy Identity (accounts.zinnvy.com). Deliberately conservative for a
 * first pass: it signs in an existing Inventory user whose email matches a
 * verified Zinnvy account, but it does NOT auto-provision a new tenant for
 * an unrecognised email — that's a product decision (self-signup vs.
 * invite-only) this endpoint shouldn't make unilaterally. Someone with no
 * matching account is sent back to the frontend with an error to explain
 * that plainly rather than silently creating a tenant on their behalf.
 */
class ZinnvyAuthController extends Controller
{
    public function redirect()
    {
        $state = Str::random(40);
        Cache::put("zinnvy_oauth_state:{$state}", true, now()->addMinutes(10));

        $query = http_build_query([
            'client_id' => config('zinnvy.client_id'),
            'redirect_uri' => config('zinnvy.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('zinnvy.scopes'),
            'state' => $state,
        ]);

        return redirect(config('zinnvy.accounts_url')."/oauth/authorize?{$query}");
    }

    public function callback(Request $request)
    {
        $frontend = config('zinnvy.frontend_callback_url');

        $state = $request->query('state');
        if (! $state || ! Cache::pull("zinnvy_oauth_state:{$state}")) {
            return redirect("{$frontend}?error=invalid_state");
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect("{$frontend}?error=missing_code");
        }

        $tokenResponse = Http::asForm()->post(config('zinnvy.accounts_url').'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('zinnvy.client_id'),
            'client_secret' => config('zinnvy.client_secret'),
            'redirect_uri' => config('zinnvy.redirect_uri'),
            'code' => $code,
        ]);

        if ($tokenResponse->failed()) {
            return redirect("{$frontend}?error=token_exchange_failed");
        }

        $accessToken = $tokenResponse->json('access_token');

        $userinfoResponse = Http::withToken($accessToken)
            ->get(config('zinnvy.accounts_url').'/oauth/userinfo');

        if ($userinfoResponse->failed()) {
            return redirect("{$frontend}?error=userinfo_failed");
        }

        $claims = $userinfoResponse->json();
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? false;

        if (! $sub || ! $email) {
            return redirect("{$frontend}?error=incomplete_profile");
        }

        $user = User::where('zinnvy_sub', $sub)->first();

        if (! $user && $emailVerified) {
            $user = User::where('email', $email)->first();

            if ($user) {
                $user->forceFill(['zinnvy_sub' => $sub])->save();
            }
        }

        if (! $user) {
            return redirect("{$frontend}?error=no_account&email=".urlencode($email));
        }

        if (! $user->tenant->is_active) {
            return redirect("{$frontend}?error=subscription_inactive");
        }

        $user->tokens()->delete();
        $apiToken = $user->createToken('auth_token')->plainTextToken;
        $user->tenant->update(['last_active_at' => now()]);

        $handoff = Str::random(40);
        Cache::put("zinnvy_handoff:{$handoff}", [
            'user_id' => $user->id,
            'token' => $apiToken,
        ], now()->addSeconds(60));

        return redirect("{$frontend}?handoff={$handoff}");
    }

    /**
     * Exchange the short-lived handoff code for the real API token — kept
     * out of the redirect URL/browser history/referrer headers, unlike the
     * token itself.
     */
    public function handoff(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);

        $payload = Cache::pull("zinnvy_handoff:{$request->input('code')}");

        if (! $payload) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired handoff code'], 404);
        }

        $user = User::with('tenant')->find($payload['user_id']);

        return response()->json([
            'user' => $user,
            'token' => $payload['token'],
            'success' => true,
        ]);
    }
}
