<?php

namespace App\Services;

use App\Models\Tenant;

class PlanLimit
{
    public static function getConfig(Tenant $tenant): array
    {
        $plan = strtolower($tenant->plan ?? 'basic');

        return config("plan_limit.{$plan}", []);
    }

    public static function getLimit(Tenant $tenant, string $key, $default = null)
    {
        $config = self::getConfig($tenant);

        return $config[$key] ?? $default;
    }

    public static function isUnlimited(Tenant $tenant, string $key): bool
    {
        return is_null(self::getLimit($tenant, $key));
    }

    public static function hasFeature(Tenant $tenant, string $featureKey): bool
    {
        $value = self::getLimit($tenant, $featureKey);

        // For booleans
        if (is_bool($value)) {
            return $value;
        }

        // For numeric (e.g., >=1 means they have at least some capacity)
        if (is_int($value) || is_float($value)) {
            return $value > 0 || $value === null; // null = unlimited
        }

        // For string levels, treat non-empty as "has feature"
        if (is_string($value)) {
            return !empty($value);
        }

        return false;
    }
}
