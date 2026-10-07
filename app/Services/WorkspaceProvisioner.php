<?php

namespace App\Services;

use App\Helpers\MailHelper;
use App\Models\Referral;
use App\Models\SmsCredit;
use App\Models\SubscriptionHistory;
use App\Models\Tenant;
use App\Models\TenantToken;
use App\Models\TokenTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a new Inventory workspace and its owner.
 *
 * Every registration starts at Zinnvy Identity, so this only ever runs for a person who
 * has already signed in there with a verified email (ZinnvyAuthController::completeSignup).
 * The owner row is linked to their Zinnvy account (`zinnvy_sub`) and gets a random,
 * unusable password: they never sign in to Inventory with a password.
 */
class WorkspaceProvisioner
{
    /** Tokens the referrer earns, by the referrer's plan. */
    private const REFERRAL_TOKENS = ['basic' => 5, 'pro' => 10, 'custom' => 15];

    /**
     * @param  array{tenant_name: string, owner_name: string, email: string, zinnvy_sub: string, currency?: string, currency_symbol?: string, ref?: ?string}  $data
     * @return array{tenant: Tenant, user: User}
     */
    public function create(array $data): array
    {
        $currency = $data['currency'] ?? 'GHS';
        $symbol = $data['currency_symbol'] ?? $currency;

        $result = DB::transaction(function () use ($data, $currency, $symbol) {
            $tenant = Tenant::create([
                'name' => $data['tenant_name'],
                'domain' => null,
                'plan' => 'pro',
                'is_active' => true,
                'subscription_ends_at' => now()->addYear(),
                'settings' => ['currency' => $currency, 'currency_symbol' => $symbol],
            ]);

            SmsCredit::create(['tenant_id' => $tenant->id, 'credits' => PlanLimit::getLimit($tenant, 'sms')]);

            $user = User::create([
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::random(40)),
                'tenant_id' => $tenant->id,
                'role' => 'Administrator',
                'first_login' => false,
            ]);
            $user->forceFill(['zinnvy_sub' => $data['zinnvy_sub'], 'email_verified_at' => now()])->save();

            SubscriptionHistory::create([
                'tenant_id' => $tenant->id,
                'from_plan' => 'basic',
                'to_plan' => $tenant->plan,
                'event_type' => 'signup',
                'amount' => null,
                'currency' => $currency,
                'effective_at' => now(),
                'meta' => ['source' => 'zinnvy_identity_signup'],
            ]);

            $this->rewardReferrer($tenant, $data['ref'] ?? null);

            return ['tenant' => $tenant, 'user' => $user];
        });

        foreach (['yawsonandrews@gmail.com', 'ugin.dev@gmail.com'] as $email) {
            MailHelper::sendEmailNotification($email, "New tenant signed up: {$result['tenant']->name}", "You have one new tenant registered.\n \n\nRegards,\nZinnvy.");
        }

        return $result;
    }

    private function rewardReferrer(Tenant $tenant, ?string $ref): void
    {
        if (! $ref) {
            return;
        }

        $referrer = Tenant::where('referral_code', $ref)->first();
        if (! $referrer || $referrer->id === $tenant->id) {
            return;
        }

        $tenant->forceFill(['referred_by_tenant_id' => $referrer->id])->save();

        $tokens = self::REFERRAL_TOKENS[$referrer->plan] ?? 5;

        TokenTransaction::create(['tenant_id' => $referrer->id, 'amount' => $tokens, 'type' => 'earn', 'source' => 'referral']);

        $balance = TenantToken::firstOrCreate(['tenant_id' => $referrer->id], ['balance' => 0]);
        $balance->update(['balance' => $balance->balance + $tokens]);

        Referral::create(['referrer_tenant_id' => $referrer->id, 'referred_tenant_id' => $tenant->id, 'tokens_awarded' => $tokens]);
    }
}
