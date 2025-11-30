<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyLoginReward;
use App\Models\Referral;
use App\Models\SmsCredit;
use App\Models\TenantToken;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TokenController extends Controller
{

    // $service->addTokens($tenantId, 10, 'referral');
    // $service->addTokens($tenantId, 3, 'completed_purchase');
    // $service->addTokens($tenantId, 50, 'admin_promo');

    public function dailyLogin(Request $request, TokenService $service)
    {
        $tenantId = Auth::user()->tenant_id;

        $result = $service->awardDailyLogin($tenantId);

        return response()->json([
            'message' => $result['earned']
                ? 'Daily login reward credited'
                : 'Already rewarded today',
        ]);
    }

    public function redeemSMS(Request $request, TokenService $service)
    {
        $request->validate([
            'tokens' => 'required|integer|min:1'
        ]);

        $tenantId = Auth::user()->tenant_id;

        // Example conversion rate:
        // 5 tokens → 50 SMS
        $conversionRate = [
            5 => 50,
            10 => 120,
            20 => 300
        ];

        if (!array_key_exists($request->tokens, $conversionRate)) {
            return response()->json(['error' => 'Invalid token tier'], 422);
        }

        $success = $service->redeemTokensForSMS(
            $tenantId,
            $request->tokens,
            $conversionRate[$request->tokens]
        );

        if (!$success) {
            return response()->json(['message' => 'Not enough tokens', 'success' => false], 422);
        }

        return response()->json(['message' => 'SMS credits added successfully', 'success' => true]);
    }

    public function summary()
    {
        $tenantId = Auth::user()->tenant_id;

        $token = TenantToken::firstOrCreate(['tenant_id' => $tenantId]);
        $sms = SmsCredit::firstOrCreate(['tenant_id' => $tenantId]);

        $reward = DailyLoginReward::firstOrCreate(['tenant_id' => $tenantId]);
        $claimedToday = $reward->last_reward_date === now()->toDateString();

        return response()->json([
            'tokens' => $token->balance,
            'sms_credits' => $sms->credits,
            'claimed_today' => $claimedToday,
        ]);
    }

    public function referrals()
    {
        $tenant = Auth::user()->tenant;

        $referrals = Referral::with('referredTenant')
            ->where('referrer_tenant_id', $tenant->id)
            ->latest()
            ->get();

        return response()->json([
            'referral_code' => $tenant->referral_code,
            'referral_link' => env('FRONTEND_URL') . '/register?ref=' . $tenant->referral_code,
            'referrals' => $referrals,
            'referrals_count' => $referrals->count(),
            'tokens_from_referrals' => $referrals->sum('tokens_awarded'),
        ]);
    }
}
