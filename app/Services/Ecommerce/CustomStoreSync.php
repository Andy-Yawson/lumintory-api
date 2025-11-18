<?php

namespace App\Services\Ecommerce;

use App\Models\StoreConnection;
use App\Models\Product;
use App\Services\Ecommerce\Interfaces\StoreSyncInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CustomStoreSync implements StoreSyncInterface
{
    public function syncProducts(StoreConnection $connection, bool $force = false): array
    {
        $settings = $connection->settings ?? [];
        $baseUrl = $settings['api_base_url'] ?? null;
        $appId = $settings['app_id'] ?? null;
        $appSecret = $settings['app_secret'] ?? null;
        $result = ['synced' => 0, 'errors' => []];

        if (!$baseUrl) {
            $result['errors'][] = 'Missing api_base_url';
            return $result;
        }

        // Example auth: App ID + Secret => base64 token (adapt for the real partner)
        $token = base64_encode(($appId ?? '') . ':' . ($appSecret ?? ''));

        try {
            $resp = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->get(rtrim($baseUrl, '/') . '/products');

            if (!$resp->successful()) {
                Log::error('CustomStoreSync::syncProducts failed', [
                    'connection_id' => $connection->id,
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                $result['errors'][] = 'Remote API returned non-200';
                return $result;
            }

            $items = $resp->json('data') ?? $resp->json();

            foreach ($items as $remote) {
                // Map remote fields to your Product model — adapt as needed.
                $payload = [
                    'tenant_id' => $connection->tenant_id,
                    'name' => $remote['name'] ?? ($remote['title'] ?? 'Unnamed'),
                    'size' => $remote['size'] ?? null,
                    'quantity' => (int) ($remote['stock'] ?? 0),
                    'unit_price' => (float) ($remote['price'] ?? 0),
                    'variations' => $remote['variations'] ?? null,
                    'description' => $remote['description'] ?? null,
                ];

                Product::updateOrCreate(
                    [
                        'tenant_id' => $connection->tenant_id,
                        'name' => $payload['name'],
                    ],
                    $payload
                );

                $result['synced']++;
            }

        } catch (\Throwable $e) {
            Log::error('CustomStoreSync exception', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage()
            ]);
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    public function handleOrderWebhook(StoreConnection $connection, array $payload): void
    {
        // Example: payload contains order => items (product external id or sku)
        // map the order to your Sale model or create a Sales queue job.
        // This is domain-specific — keep minimal and safe here.

        Log::info('CustomStoreSync::handleOrderWebhook', [
            'connection_id' => $connection->id,
            'payload_preview' => array_slice($payload, 0, 3)
        ]);

        // TODO: implement mapping from remote order -> local Sale
        // e.g. foreach line_items: find product by sku or name and create a Sale record
    }

    public function verifyWebhook(StoreConnection $connection, string $body, array $headers): bool
    {
        $secret = $connection->webhook_secret ?? ($connection->settings['webhook_secret'] ?? null);
        if (!$secret) {
            // No secret, optionally accept or reject. Safer: reject.
            return false;
        }

        $signatureHeader = $headers['x-app-signature'][0] ?? ($headers['X-App-Signature'][0] ?? null);
        if (!$signatureHeader) {
            return false;
        }

        // HMAC SHA256
        $calculated = hash_hmac('sha256', $body, $secret);

        return hash_equals($calculated, $signatureHeader);
    }
}
