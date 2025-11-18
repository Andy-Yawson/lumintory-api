<?php

namespace App\Services\Ecommerce;

use App\Models\StoreConnection;
use App\Models\Product;
use App\Services\Ecommerce\Interfaces\StoreSyncInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyStoreSync implements StoreSyncInterface
{
    public function syncProducts(StoreConnection $connection, bool $force = false): array
    {
        $settings = $connection->settings ?? [];
        $domain = $connection->domain;
        $accessToken = $connection->access_token;
        $result = ['synced' => 0, 'errors' => []];

        if (!$domain || !$accessToken) {
            $result['errors'][] = 'Missing domain or access token';
            return $result;
        }

        try {
            $resp = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
            ])->get("https://{$domain}/admin/api/2025-10/products.json");

            if (!$resp->successful()) {
                $result['errors'][] = 'Shopify API returned non-200';
                Log::error('Shopify sync error', ['body' => $resp->body(), 'domain' => $domain]);
                return $result;
            }

            $products = $resp->json('products') ?? [];

            foreach ($products as $p) {
                // map Shopify product -> Product model
                $payload = [
                    'tenant_id' => $connection->tenant_id,
                    'name' => $p['title'] ?? 'Unnamed',
                    'quantity' => 0, // Shopify inventory requires extra calls
                    'unit_price' => (float) ($p['variants'][0]['price'] ?? 0),
                    'variations' => array_map(function ($v) {
                        return [
                            'type' => $v['title'] ?? null,
                            'price' => (float) ($v['price'] ?? 0)
                        ];
                    }, $p['variants'] ?? []),
                    'description' => $p['body_html'] ?? null,
                ];

                Product::updateOrCreate(
                    ['tenant_id' => $connection->tenant_id, 'name' => $payload['name']],
                    $payload
                );

                $result['synced']++;
            }
        } catch (\Throwable $e) {
            Log::error('ShopifyStoreSync exception', ['error' => $e->getMessage()]);
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    public function handleOrderWebhook(StoreConnection $connection, array $payload): void
    {
        // Parse Shopify order payload, map to Sale records.
        Log::info('ShopifyStoreSync::handleOrderWebhook', [
            'connection_id' => $connection->id,
            'order_id' => $payload['id'] ?? null,
        ]);

        // TODO: map order -> local Sale creation (respect tenant owner, currency, etc.)
    }

    public function verifyWebhook(StoreConnection $connection, string $body, array $headers): bool
    {
        $hmacHeader = $headers['x-shopify-hmac-sha256'][0] ?? ($headers['X-Shopify-Hmac-Sha256'][0] ?? null);
        if (!$hmacHeader)
            return false;

        $computed = base64_encode(hash_hmac('sha256', $body, $connection->webhook_secret ?? '', true));
        return hash_equals($computed, $hmacHeader);
    }
}
