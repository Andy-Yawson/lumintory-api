<?php

namespace App\Services;

use App\Models\TenantToken;
use App\Models\TokenTransaction;
use App\Models\SmsCredit;
use App\Models\DailyLoginReward;
use Carbon\Carbon;

class TokenService
{
    public function awardDailyLogin($tenantId)
    {
        $reward = DailyLoginReward::firstOrCreate(['tenant_id' => $tenantId]);
        $today = Carbon::today()->toDateString();

        if ($reward->last_reward_date === $today) {
            return ['earned' => false];
        }

        // Award tokens
        $this->addTokens($tenantId, 1, 'daily_login');

        $reward->update(['last_reward_date' => $today]);

        return ['earned' => true];
    }

    public function addTokens($tenantId, $amount, $source)
    {
        $token = TenantToken::firstOrCreate(['tenant_id' => $tenantId]);
        $token->increment('balance', $amount);

        TokenTransaction::create([
            'tenant_id' => $tenantId,
            'type' => 'earn',
            'source' => $source,
            'amount' => $amount
        ]);
    }

    public function redeemTokensForSMS($tenantId, $tokens, $smsCredits)
    {
        $token = TenantToken::firstOrCreate(['tenant_id' => $tenantId]);

        if ($token->balance < $tokens) {
            return false;
        }

        // Deduct tokens
        $token->decrement('balance', $tokens);

        TokenTransaction::create([
            'tenant_id' => $tenantId,
            'type' => 'redeem',
            'source' => 'redeem_sms',
            'amount' => $tokens
        ]);

        // Add SMS credits
        $sms = SmsCredit::firstOrCreate(['tenant_id' => $tenantId]);
        $sms->increment('credits', $smsCredits);

        return true;
    }
}
