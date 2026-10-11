<?php

namespace App\Services\Pay;

use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a PAID Zinnvy Pay payment into a plan. The one place a subscription is
 * granted for money. It never trusts the caller: the amount and plan are checked
 * against our own price list, and a payment can only ever be applied once.
 */
class PaySubscriptionActivator
{
    public const PRODUCT = 'zinnvy-inventory';

    /** @param array<string, mixed> $payment A payment_intent object fetched from Pay. @return string applied|already_applied|ignored */
    public function activate(array $payment): string
    {
        $meta = $payment['metadata'] ?? [];
        if (($payment['status'] ?? null) !== 'succeeded' || ($payment['product'] ?? null) !== self::PRODUCT || empty($meta['tenant_id']) || empty($meta['plan']) || empty($meta['cadence'])) {
            return 'ignored';
        }

        $plan = SubscriptionPlan::findByName($meta['plan']);
        $price = $plan ? $plan->{'price_'.($meta['cadence'] === 'yearly' ? 'yearly' : 'monthly')} : null;
        if (! $plan || ! $price || (int) round($price * 100) !== (int) $payment['amount'] || strtoupper($payment['currency']) !== strtoupper($plan->currency)) {
            Log::warning('Pay payment does not match the price list; subscription not granted', ['payment' => $payment['id'] ?? null]);

            return 'ignored';
        }

        return DB::transaction(function () use ($payment, $meta, $plan, $price) {
            $tenant = Tenant::whereKey($meta['tenant_id'])->lockForUpdate()->first();
            if (! $tenant) {
                return 'ignored';
            }
            if (SubscriptionHistory::where('payment_reference', $payment['id'])->exists()) {
                return 'already_applied';
            }

            // Renewing early extends from the current end date instead of wasting paid time.
            $from = $tenant->subscription_ends_at && Carbon::parse($tenant->subscription_ends_at)->isFuture() ? Carbon::parse($tenant->subscription_ends_at) : now();
            $ends = $meta['cadence'] === 'yearly' ? $from->addYear() : $from->addMonth();

            $order = ['basic' => 1, 'pro' => 2, 'custom' => 3];
            $before = $tenant->plan;
            $event = match (true) {
                $before === $plan->name => 'renewal',
                ($order[$plan->name] ?? 1) > ($order[$before] ?? 1) => 'upgrade',
                default => 'downgrade',
            };

            $settings = $tenant->settings ?? [];
            unset($settings['pending_pay_id']);
            $tenant->update(['plan' => $plan->name, 'subscription_ends_at' => $ends, 'is_active' => true, 'settings' => $settings]);

            SubscriptionHistory::create([
                'tenant_id' => $tenant->id,
                'from_plan' => $before,
                'to_plan' => $plan->name,
                'event_type' => $event,
                'amount' => $price,
                'currency' => $plan->currency,
                'payment_reference' => $payment['id'],
                'gateway' => 'zinnvy_pay',
                'effective_at' => now(),
                'meta' => ['cadence' => $meta['cadence'], 'pay_reference' => $payment['reference'] ?? null],
            ]);

            return 'applied';
        });
    }
}
