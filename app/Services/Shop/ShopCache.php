<?php

namespace App\Services\Shop;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tells the shop site (shop.zinnvy.com) to drop its cached API reads, so a shop's new
 * colour, logo, settings or products show straight away. Sent once the request finishes and
 * at most every few seconds, so bulk edits and imports don't flood it. Never blocks or fails a request.
 */
class ShopCache
{
    public static function bust(): void
    {
        $secret = config('services.shop.revalidate_secret');
        if (! $secret || app()->runningUnitTests() || ! Cache::add('shop-cache-bust', 1, 3)) {
            return;
        }
        $url = rtrim((string) config('services.shop.url'), '/').'/api/revalidate';

        app()->terminating(function () use ($url, $secret) {
            rescue(fn () => Http::timeout(3)->withHeaders(['x-revalidate-secret' => $secret])->post($url), report: false);
        });
    }
}
