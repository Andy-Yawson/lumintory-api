<?php

namespace App\Services\Integrations;

use App\Models\StoreConnection;
use Illuminate\Support\Facades\Log;

/** Pulls a connected store's catalogue and applies its webhook events. */
class StoreSyncRunner
{
    public function __construct(
        private CatalogSync $catalog,
        private ShopifyService $shopify,
        private WooCommerceService $woo,
    ) {}

    /** Full catalogue pull. Records the outcome on the connection either way. */
    public function syncProducts(StoreConnection $c): void
    {
        try {
            $generator = $c->provider === StoreConnection::SHOPIFY ? $this->shopify->products($c) : $this->woo->products($c);
            $map = $c->provider === StoreConnection::SHOPIFY ? [ShopifyService::class, 'mapProduct'] : [WooCommerceService::class, 'mapProduct'];

            $count = 0;
            $batch = [];
            foreach ($generator as $p) {
                $batch[] = $map($p);
                if (count($batch) === 50) {
                    $count += $this->flush($c, $batch);
                }
            }
            $count += $this->flush($c, $batch);

            $c->update(['product_count' => $count, 'last_synced_at' => now(), 'last_error' => null, 'status' => 'active']);
        } catch (\Throwable $e) {
            Log::warning('Store product sync failed', ['connection' => $c->id, 'error' => $e->getMessage()]);
            $c->update(['last_error' => 'Could not sync products: ' . substr($e->getMessage(), 0, 200)]);
        }
    }

    private function flush(StoreConnection $c, array &$batch): int
    {
        if (! $batch) {
            return 0;
        }
        $res = $this->catalog->products($c->tenant_id, $batch);
        $batch = [];

        return $res['created'] + $res['updated'];
    }

    /** @param string $topic e.g. "orders/paid" (Shopify) or "order.created" (Woo) */
    public function handleEvent(StoreConnection $c, string $topic, array $payload): void
    {
        $tenantId = $c->tenant_id;
        $shopify = $c->provider === StoreConnection::SHOPIFY;

        switch ($topic) {
            case 'products/create':
            case 'products/update':
            case 'product.created':
            case 'product.updated':
                if (! $shopify) {
                    $payload = $this->woo->withVariations($c, $payload); // Woo's webhook omits variations
                }
                $this->catalog->products($tenantId, [$shopify ? ShopifyService::mapProduct($payload) : WooCommerceService::mapProduct($payload)]);
                break;

            case 'products/delete':
            case 'product.deleted':
                $shopify ? ShopifyService::retireProduct($tenantId, (string) $payload['id']) : WooCommerceService::retireProduct($tenantId, (string) $payload['id']);
                break;

            case 'orders/paid':
            case 'order.created':
            case 'order.updated':
                $order = $shopify ? ShopifyService::mapOrder($tenantId, $payload) : WooCommerceService::mapOrder($tenantId, $payload);
                if ($order && $order['items']) {
                    $res = $this->catalog->orders($tenantId, [$order], storeOwnsStock: true);
                    if ($res['errors']) {
                        $c->update(['last_error' => 'Order ' . $order['external_order_id'] . ': ' . $res['errors'][0]['message']]);
                    }
                }
                break;

            case 'app/uninstalled':
                $c->update(['status' => 'disconnected', 'credentials' => null, 'last_error' => 'The app was uninstalled from the store.']);
                break;
        }
    }
}
