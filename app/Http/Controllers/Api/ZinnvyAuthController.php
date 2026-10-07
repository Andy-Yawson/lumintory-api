<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Zinnvy" — this app acting as an OAuth *client* against Zinnvy Identity
 * (accounts.zinnvy.com). Identity owns every account and every sign-up:
 *
 *  - an Inventory user whose email matches a verified Zinnvy account is signed in
 *    (invited teammates are linked by email on their first sign-in);
 *  - a verified Zinnvy account with NO Inventory workspace is handed to the sign-up
 *    step (signupInfo / completeSignup), which only asks for the business name and
 *    currency and then creates the workspace linked to that Zinnvy account.
 *
 * Inventory never creates credentials of its own for anyone.
 */
class ZinnvyAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $state = Str::random(40);
        // `ref` (a referral code from /register?ref=…) rides along so a brand-new workspace can credit the referrer.
        Cache::put("zinnvy_oauth_state:{$state}", ['ref' => $request->query('ref')], now()->addMinutes(30));

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
        $stateData = $state ? Cache::pull("zinnvy_oauth_state:{$state}") : null;
        if (! $stateData) {
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

        if (! $user && ! $emailVerified) {
            // Never link an invite, and never create a workspace, on an email Zinnvy hasn't verified:
            // that would let someone claim an address they don't own.
            return redirect("{$frontend}?error=email_unverified&email=".urlencode($email));
        }

        if (! $user) {
            // Verified at Zinnvy, but no workspace here yet: let them create one (see completeSignup).
            $code = Str::random(40);
            Cache::put("zinnvy_signup:{$code}", [
                'sub' => $sub,
                'email' => $email,
                'name' => $claims['name'] ?? ($claims['given_name'] ?? Str::before($email, '@')),
                'ref' => is_array($stateData) ? ($stateData['ref'] ?? null) : null,
            ], now()->addMinutes(30));

            return redirect("{$frontend}?signup={$code}");
        }

        if (! $user->tenant->is_active) {
            return redirect("{$frontend}?error=subscription_inactive");
        }

        // First successful sign-in clears the "Invited" state shown on the Team page.
        $user->forceFill(['first_login' => false])->save();

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

    /** Who is signing up (shown on the "name your workspace" step). Does not consume the code. */
    public function signupInfo(string $code)
    {
        $payload = Cache::get("zinnvy_signup:{$code}");

        if (! $payload) {
            return response()->json(['success' => false, 'message' => 'This sign-up link has expired. Please start again.'], 404);
        }

        return response()->json(['success' => true, 'name' => $payload['name'], 'email' => $payload['email']]);
    }

    /** Create the workspace for a verified Zinnvy account, then sign them in. One use per code. */
    public function completeSignup(Request $request, WorkspaceProvisioner $provisioner)
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'tenant_name' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'currency_symbol' => ['nullable', 'string', 'max:10'],
        ]);

        $payload = Cache::pull("zinnvy_signup:{$data['code']}");

        if (! $payload) {
            return response()->json(['success' => false, 'message' => 'This sign-up link has expired. Please start again.'], 404);
        }

        // Someone else may have linked this email/account in the meantime (e.g. an invite): never create a duplicate.
        if (User::where('zinnvy_sub', $payload['sub'])->orWhere('email', $payload['email'])->exists()) {
            return response()->json(['success' => false, 'message' => 'This Zinnvy account already has an Inventory workspace. Sign in instead.'], 409);
        }

        ['tenant' => $tenant, 'user' => $user] = $provisioner->create([
            'tenant_name' => trim($data['tenant_name']),
            'owner_name' => $payload['name'],
            'email' => $payload['email'],
            'zinnvy_sub' => $payload['sub'],
            'currency' => $data['currency'] ?? 'GHS',
            'currency_symbol' => $data['currency_symbol'] ?? ($data['currency'] ?? 'GHS'),
            'ref' => $payload['ref'] ?? null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        $tenant->update(['last_active_at' => now()]);

        return response()->json([
            'success' => true,
            'user' => $user->load('tenant'),
            'tenant' => $tenant->fresh(),
            'token' => $token,
        ], 201);
    }
}
