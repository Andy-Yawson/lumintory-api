<?php

namespace App\Http\Controllers\Api;

use App\Helpers\MailHelper;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\SmsCredit;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\TenantToken;
use App\Models\TokenTransaction;
use App\Services\PlanLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials', 'success' => false], 404);
        }

        if (!$user->tenant->is_active) {
            return response()->json(['message' => 'Subscription inactive. Please renew.'], 403);
        }

        // Delete old tokens if needed
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('auth_token')->plainTextToken;

        if ($user->tenant) {
            $user->tenant->update(['last_active_at' => now()]);
        }

        return response()->json([
            'user' => $user,
            'token' => $token,
            'success' => true
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        return $request->user()->load('tenant');
    }

    /**
     * Retired: every registration now starts at Zinnvy Identity ("Continue with Zinnvy"),
     * so Inventory no longer creates password accounts. Kept as a clear 410 for old clients.
     */
    public function registerTenant(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Registration now happens through Zinnvy. Use "Continue with Zinnvy" to create your account and workspace.',
        ], 410);
    }

    public function activateSubscription(Request $request)
    {
        // tenant_id used to be taken from the request body with no check
        // that it belonged to the caller — any authenticated user of any
        // tenant could activate (or silently extend/reset) a completely
        // different tenant's subscription just by guessing an id.
        // There is no payment step behind this endpoint, so letting a workspace call it
        // would hand out a paid plan for free. Plan changes go through support / the
        // admin console (AdminPlanController::assignToTenant) until payments exist.
        if (Auth::user()?->role !== 'SuperAdmin') {
            abort(403, 'Plan changes are handled by the Zinnvy team. Please open a billing ticket.');
        }

        $validated = $request->validate([
            'plan' => 'required|in:monthly,yearly',
        ]);

        // 'monthly'/'yearly' describe billing cadence, not a feature tier —
        // tenants.plan is a DB enum('basic','pro','custom'). Writing
        // 'monthly'/'yearly' into it directly (as this used to) fails at
        // the database level on every call; this endpoint's job is to
        // grant the paid (pro) tier, billed at whichever cadence the
        // caller chose.
        $tenant = Auth::user()->tenant;
        $tenant->plan = 'pro';
        $tenant->subscription_ends_at = $validated['plan'] === 'monthly'
            ? now()->addMonth()
            : now()->addYear();
        $tenant->is_active = true;
        $tenant->save();

        return response()->json([
            'message' => 'Subscription activated successfully.',
            'tenant' => $tenant,
        ]);
    }

    /**
     * Invite a teammate. They sign in with "Continue with Zinnvy" using this email
     * (the OAuth callback links the Zinnvy account to this user by verified email),
     * so no password is collected here. A random unusable password is set so the row
     * is valid; `first_login` stays true until their first sign-in, which marks them "Invited".
     * Passing a password is still accepted for API clients that want password sign-in.
     */
    public function addUser(Request $request)
    {
        $this->requireTenantAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role' => 'required|in:Administrator,Sales',
        ]);

        $inviter = Auth::user();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password'] ?? Str::random(40)),
            'tenant_id' => $inviter->tenant_id,
            'role' => $validated['role'],
            'first_login' => true,
        ]);

        $this->sendInvite($user, $inviter);

        return response()->json([
            'message' => 'Invitation sent. They can sign in with Zinnvy using ' . $user->email . '.',
            'user' => $user,
        ], 201);
    }

    public function resendInvite(User $user)
    {
        $this->requireTenantAdmin();
        $this->ensureSameTenant($user);

        if (! $user->first_login) {
            return response()->json(['message' => 'This person has already signed in.'], 422);
        }

        $this->sendInvite($user, Auth::user());

        return response()->json(['message' => 'Invitation sent again.']);
    }

    /** Revoke a teammate's access. Never yourself, and never the last Administrator. */
    public function removeUser(User $user)
    {
        $this->requireTenantAdmin();
        $this->ensureSameTenant($user);

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove yourself.'], 422);
        }

        if ($user->role === 'Administrator'
            && User::where('tenant_id', $user->tenant_id)->where('role', 'Administrator')->count() <= 1) {
            return response()->json(['message' => 'A workspace needs at least one Administrator.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Access removed.']);
    }

    private function requireTenantAdmin(): void
    {
        if (Auth::user()?->role !== 'Administrator') {
            abort(403, 'Only a workspace Administrator can manage the team.');
        }
    }

    private function ensureSameTenant(User $user): void
    {
        if ($user->tenant_id !== Auth::user()->tenant_id || $user->role === 'SuperAdmin') {
            abort(404);
        }
    }

    private function sendInvite(User $user, User $inviter): void
    {
        $workspace = $inviter->tenant?->name ?? 'a workspace';
        $loginUrl = rtrim((string) config('services.frontend_url'), '/') . '/login';

        MailHelper::sendEmailNotification(
            $user->email,
            "{$inviter->name} invited you to {$workspace} on Zinnvy Inventory",
            "Hi {$user->name},\n\n{$inviter->name} added you to {$workspace} as " . ($user->role === 'Administrator' ? 'an Administrator' : 'a Sales user') . ".\n\n"
            . "To get in, open {$loginUrl} and choose \"Continue with Zinnvy\", signing in with this email address ({$user->email}).\n"
            . "Don't have a Zinnvy account yet? Create one free at https://accounts.zinnvy.com using this same email, verify it, then come back and sign in.\n\n"
            . "Regards,\nZinnvy."
        );
    }

    public function listUsers()
    {
        $tenantId = Auth::user()->tenant_id;
        $users = User::where('tenant_id', $tenantId)->get();

        return response()->json($users);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string|min:8',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = Auth::user();

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'success' => false,
            ], 400);
        }

        $user->password = Hash::make($data['password']);
        $user->first_login = false;
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
            'success' => true,
        ]);
    }

    public function addUserAdmin(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $tenantId = Auth::user()->tenant_id;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $tenantId,
            'role' => 'SuperAdmin',
            'first_login' => true,
        ]);

        return response()->json([
            'message' => 'User added successfully.',
            'user' => $user,
        ], 201);
    }

    public function contactUs(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'message' => 'required|string',
        ]);

        $subject = 'Zinnvy Contact Us: ' . $validated['name'] . ' (' . $validated['email'] . ')';
        foreach (['yawsonandrews@gmail.com', 'ugin.dev@gmail.com'] as $email) {
            MailHelper::sendEmailNotification($email, $subject, $validated['message']);
        }

        return response()->json([
            'message' => 'Message sent successfully.',
            'success' => true,
        ]);
    }
}
