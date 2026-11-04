<?php

namespace App\Services\Dispatch;

use App\Models\Shop;

/** Distance, service area and the delivery fee from the admin-set bands. */
class Pricing
{
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        // Straight line understates a road trip; 1.3 is the usual city allowance.
        return round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)) * 1.3, 2);
    }

    public static function inServiceArea(float $lat, float $lng): bool
    {
        $a = Settings::get('service_area');

        return $lat >= $a['min_lat'] && $lat <= $a['max_lat'] && $lng >= $a['min_lng'] && $lng <= $a['max_lng'];
    }

    /**
     * @return array{ok: bool, fee: int, distance_km: ?float, reason: ?string}
     */
    public static function quote(Shop $shop, ?float $lat, ?float $lng): array
    {
        if (! $shop->rider_delivery || $shop->pickup_lat === null) {
            return ['ok' => false, 'fee' => 0, 'distance_km' => null, 'reason' => 'This shop does not offer Zinnvy rider delivery.'];
        }
        if ($lat === null || $lng === null) {
            return ['ok' => false, 'fee' => 0, 'distance_km' => null, 'reason' => 'Share your location so we can price the delivery.'];
        }
        if (! self::inServiceArea($lat, $lng) || ! self::inServiceArea((float) $shop->pickup_lat, (float) $shop->pickup_lng)) {
            return ['ok' => false, 'fee' => 0, 'distance_km' => null, 'reason' => 'Rider delivery is only available in Accra for now.'];
        }
        $km = self::distanceKm((float) $shop->pickup_lat, (float) $shop->pickup_lng, $lat, $lng);
        if ($km > Settings::get('max_km')) {
            return ['ok' => false, 'fee' => 0, 'distance_km' => $km, 'reason' => 'That address is too far for rider delivery.'];
        }
        foreach (Settings::get('fee_bands') as $band) {
            if ($km <= $band['up_to_km']) {
                return ['ok' => true, 'fee' => (int) $band['fee'], 'distance_km' => $km, 'reason' => null];
            }
        }
        $last = collect(Settings::get('fee_bands'))->last();

        return ['ok' => true, 'fee' => (int) $last['fee'], 'distance_km' => $km, 'reason' => null];
    }

    /** The split of one delivery, from the current rates. Rounds so the three parts always add up. */
    public static function split(int $goods, int $fee): array
    {
        $riderPct = (float) Settings::get('rider_fee_percent');
        $goodsPct = (float) Settings::get('goods_commission_percent');
        $rider = (int) round($fee * $riderPct / 100);
        $commission = (int) round($goods * $goodsPct / 100);

        return ['rider_share' => $rider, 'goods_commission' => $commission, 'rates' => ['rider_fee_percent' => $riderPct, 'goods_commission_percent' => $goodsPct]];
    }
}
