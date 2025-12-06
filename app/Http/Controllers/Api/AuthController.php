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

    public function registerTenant(Request $request)
    {
        $validated = $request->validate([
            'tenant_name' => 'required|string|max:255',
            'user_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'ref' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'currency_symbol' => 'nullable|string|max:10',
        ]);

        // If honeypot field is filled, silently reject
        if (!empty($validated['website'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid submission.',
            ], 422);
        }

        $currency = $validated['currency'] ?? 'GHS';
        $currencySymbol = $validated['currency_symbol'] ?? 'GHS';


        // Create tenant
        $tenant = Tenant::create([
            'name' => $validated['tenant_name'],
            'domain' => null,
            'plan' => 'pro',
            'is_active' => true,
            'subscription_ends_at' => Carbon::now()->addYear(),
            'settings' => [
                'currency' => $currency,
                'currency_symbol' => $currencySymbol,
            ],
        ]);

        $initialSms = PlanLimit::getLimit($tenant, 'sms');

        SmsCredit::create([
            'tenant_id' => $tenant->id,
            'credits' => $initialSms,
        ]);

        // Create user
        $user = User::create([
            'name' => $validated['user_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $tenant->id,
            'role' => 'Administrator',
        ]);

        SubscriptionHistory::create([
            'tenant_id' => $tenant->id,
            'from_plan' => 'basic',
            'to_plan' => $tenant->plan,
            'event_type' => 'signup',
            'amount' => null,
            'currency' => $currency,
            'effective_at' => now(),
            'meta' => [
                'source' => 'self_signup',
            ],
        ]);

        // ----------- REFERRAL ------------
        if (!empty($validated['ref'])) {
            $referrerTenant = Tenant::where('referral_code', $validated['ref'])->first();

            if ($referrerTenant && $referrerTenant->id !== $tenant->id) {

                $tenant->referred_by_tenant_id = $referrerTenant->id;
                $tenant->save();

                $refTokenReward = $planLimits['referral'] ?? 'basic';
                $tokensToAward = match ($refTokenReward) {
                    'basic' => 5,
                    'tiered' => 10,
                    'custom' => 15,
                };

                TokenTransaction::create([
                    'tenant_id' => $referrerTenant->id,
                    'amount' => $tokensToAward,
                    'type' => 'earn',
                    'source' => 'referral',
                    'meta' => [
                        'referred_tenant_id' => $tenant->id,
                    ],
                ]);

                $tt = TenantToken::where('tenant_id', $referrerTenant->id)->first();
                $tt->update([
                    'balance' => $tt->balance + $tokensToAward
                ]);

                Referral::create([
                    'referrer_tenant_id' => $referrerTenant->id,
                    'referred_tenant_id' => $tenant->id,
                    'tokens_awarded' => $tokensToAward,
                ]);
            }
        }
        // ------------------------------------------------

        $token = $user->createToken('auth_token')->plainTextToken;

        foreach (["yawsonandrews@gmail.com", "ugin.dev@gmail.com"] as $email) {
            MailHelper::sendEmailNotification($email, "New tenant signed up: {$tenant->name}", "You have one new tenant registered.\n \n\nRegards,\nZinnvy.");
        }

        return response()->json([
            'message' => 'Tenant registered successfully. Please activate subscription.',
            'tenant' => $tenant->fresh(),
            'user' => $user,
            'token' => $token,
        ], 201);
    }


    public function activateSubscription(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'plan' => 'required|in:monthly,yearly',
        ]);

        $tenant = Tenant::findOrFail($validated['tenant_id']);
        $tenant->plan = $validated['plan'];
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

    public function addUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:Administrator,Sales',
        ]);

        $tenantId = Auth::user()->tenant_id;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $tenantId,
            'role' => $validated['role'],
            'first_login' => true,
        ]);

        return response()->json([
            'message' => 'User added successfully.',
            'user' => $user,
        ], 201);
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
