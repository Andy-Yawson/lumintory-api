<?php

namespace App\Services\Dispatch;

use Illuminate\Support\Facades\DB;

/**
 * Rules staff can change without a release: commission, fee bands, timings. Values are read through here so a
 * missing row always falls back to a safe default. Money is in pesewas.
 */
class Settings
{
    public const DEFAULTS = [
        'rider_fee_percent' => 80,          // rider's share of the delivery fee
        'goods_commission_percent' => 0,    // Zinnvy's share of the goods value
        'fee_bands' => [                    // distance (km) up to -> fee
            ['up_to_km' => 5, 'fee' => 1500],
            ['up_to_km' => 10, 'fee' => 2500],
            ['up_to_km' => 20, 'fee' => 4000],
        ],
        'max_km' => 20,
        'offer_seconds' => 120,
        'failed_trip_fee' => 500,           // rider's pay when the customer can't pay or refuses at the door
        'platform_tenant_id' => null,        // the internal workspace rider sign-ins belong to (set by dispatch:setup)
        'owner_hold_minutes' => 1440,
        'rider_hold_minutes' => 0,
        // Accra (greater): outside this box, riders are not offered.
        'service_area' => ['min_lat' => 5.40, 'max_lat' => 5.85, 'min_lng' => -0.45, 'max_lng' => 0.10],
    ];

    public static function get(string $key): mixed
    {
        $row = DB::table('dispatch_settings')->where('key', $key)->value('value');

        return $row !== null ? json_decode($row, true) : (self::DEFAULTS[$key] ?? null);
    }

    public static function all(): array
    {
        $rows = DB::table('dispatch_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();

        return $rows + self::DEFAULTS;
    }

    public static function put(string $key, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::DEFAULTS), 422, 'Unknown setting.');
        DB::table('dispatch_settings')->upsert([['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]], ['key'], ['value', 'updated_at']);
    }
}
