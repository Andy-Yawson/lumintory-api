<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

class PlanLimit
{
    /**
     * Returns the limits array for the tenant's plan.
     * Reads from DB first (admin-editable), falls back to config file.
     */
    public static function getConfig(Tenant $tenant): array
    {
        $planName = strtolower($tenant->plan ?? 'basic');

        // Cache per plan name (busted when admin saves a plan)
        return Cache::remember("plan_limits:{$planName}", 300, function () use ($planName) {
            $plan = SubscriptionPlan::where('name', $planName)->where('is_active', true)->first();

            if ($plan && !empty($plan->limits)) {
                return $plan->limits;
            }

            // Fallback to config file while DB is empty / during migration
            return config("plan_limits.{$planName}", []);
        });
    }

    public static function getLimit(Tenant $tenant, string $key, $default = null)
    {
        return static::getConfig($tenant)[$key] ?? $default;
    }

    public static function isUnlimited(Tenant $tenant, string $key): bool
    {
        return is_null(static::getLimit($tenant, $key));
    }

    public static function hasFeature(Tenant $tenant, string $featureKey): bool
    {
        $value = static::getLimit($tenant, $featureKey);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0 || $value === null;
        }

        if (is_string($value)) {
            return !empty($value);
        }

        return false;
    }

    /** Call after saving a plan to invalidate cached limits. */
    public static function bustCache(string $planName): void
    {
        Cache::forget("plan_limits:{$planName}");
    }
}
