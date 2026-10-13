<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Services\Integrations\ShopifyService;
use App\Services\Integrations\StoreSyncRunner;
use App\Services\Integrations\WooCommerceService;
use Illuminate\Http\Request;

/**
 * Inbound webhooks from Shopify and WooCommerce. Nothing is acted on until the
 * body's HMAC signature matches; unsigned or mis-signed calls get a 401 and
 * touch nothing. Verified events always answer 200 so the store doesn't retry
 * work we've already done.
 */
class StoreWebhookController extends Controller
{
    /** Shopify's mandatory privacy webhooks — we keep no per-customer Shopify data. */
    private const SHOPIFY_PRIVACY_TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

    public function shopify(Request $request, ShopifyService $shopify, StoreSyncRunner $runner)
    {
        $raw = $request->getContent();
        if (! $shopify->validWebhookHmac($raw, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        if (in_array($topic, self::SHOPIFY_PRIVACY_TOPICS, true)) {
            return response()->json(['ok' => true]);
        }

        $connection = StoreConnection::where('provider', StoreConnection::SHOPIFY)
            ->where('store_url', strtolower((string) $request->header('X-Shopify-Shop-Domain')))
            ->first();

        if ($connection && ($connection->isActive() || $topic === 'app/uninstalled')) {
            $runner->handleEvent($connection, $topic, (array) json_decode($raw, true));
        }

        return response()->json(['ok' => true]);
    }

    public function woocommerce(Request $request, string $uuid, WooCommerceService $woo, StoreSyncRunner $runner)
    {
        $connection = StoreConnection::where('provider', StoreConnection::WOOCOMMERCE)->where('uuid', $uuid)->first();
        $raw = $request->getContent();

        if (! $connection || ! $woo->validWebhookSignature($connection, $raw, $request->header('X-WC-Webhook-Signature'))) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $payload = json_decode($raw, true);

        // Woo pings the delivery URL with a form-encoded "webhook_id=…" when a webhook is created.
        if ($connection->isActive() && is_array($payload) && $topic !== '') {
            $runner->handleEvent($connection, $topic, $payload);
        }

        return response()->json(['ok' => true]);
    }
}
