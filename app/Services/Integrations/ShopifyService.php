<?php

namespace App\Services\Integrations;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StoreConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shopify public-app OAuth, Admin REST calls, webhook registration and
 * payload mapping. All HTTP goes through Laravel's client so it can be faked.
 */
class ShopifyService
{
    public const WEBHOOK_TOPICS = ['orders/paid', 'products/create', 'products/update', 'products/delete', 'app/uninstalled'];

    public function configured(): bool
    {
        return filled(config('services.shopify.client_id')) && filled(config('services.shopify.client_secret'));
    }

    /** "my-shop" / "https://my-shop.myshopify.com/" → "my-shop.myshopify.com", or null if it isn't one. */
    public static function normaliseShop(string $input): ?string
    {
        $shop = strtolower(trim($input));
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim(explode('/', $shop)[0], '/');
        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop) ? $shop : null;
    }

    public function redirectUri(): string
    {
        return url('/api/v1/stores/shopify/callback');
    }

    public function authorizeUrl(string $shop, string $state): string
    {
        return "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => config('services.shopify.scopes'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    /** OAuth redirect integrity: HMAC over the sorted query string (minus hmac). */
    public function validOAuthHmac(array $query): bool
    {
        $hmac = $query['hmac'] ?? null;
        if (! is_string($hmac) || $hmac === '') {
            return false;
        }
        unset($query['hmac']);
        ksort($query);
        $message = collect($query)->map(fn ($v, $k) => "{$k}=" . (is_array($v) ? implode(',', $v) : $v))->implode('&');

        return hash_equals(hash_hmac('sha256', $message, (string) config('services.shopify.client_secret')), $hmac);
    }

    /** Webhook integrity: base64 HMAC-SHA256 of the raw body. */
    public function validWebhookHmac(string $rawBody, ?string $header): bool
    {
        if (! $header) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, (string) config('services.shopify.client_secret'), true)), $header);
    }

    public function exchangeToken(string $shop, string $code): ?string
    {
        $res = Http::asJson()->acceptJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
        ]);

        return $res->successful() ? $res->json('access_token') : null;
    }

    private function api(StoreConnection $c): PendingRequest
    {
        $version = config('services.shopify.api_version');

        return Http::withHeaders(['X-Shopify-Access-Token' => (string) $c->credential('access_token')])
            ->acceptJson()->timeout(30)
            ->baseUrl("https://{$c->store_url}/admin/api/{$version}");
    }

    /** Register our webhook endpoint for each topic; tolerate "already exists". */
    public function registerWebhooks(StoreConnection $c): void
    {
        $address = url('/api/v1/webhooks/shopify');
        foreach (self::WEBHOOK_TOPICS as $topic) {
            $this->api($c)->post('/webhooks.json', ['webhook' => ['topic' => $topic, 'address' => $address, 'format' => 'json']]);
        }
    }

    /** Walks every page of products, yielding Shopify product payloads. */
    public function products(StoreConnection $c): \Generator
    {
        $res = $this->api($c)->get('/products.json', ['limit' => 250]);

        while (true) {
            $res->throw();
            foreach ($res->json('products', []) as $p) {
                yield $p;
            }

            $next = $this->nextLink($res->header('Link'));
            if (! $next) {
                return;
            }
            $res = Http::withHeaders(['X-Shopify-Access-Token' => (string) $c->credential('access_token')])->acceptJson()->timeout(30)->get($next);
        }
    }

    private function nextLink(?string $header): ?string
    {
        if ($header && preg_match('/<([^>]+)>;\s*rel="next"/', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Shopify product → CatalogSync product. "Default Title" single-variant products stay flat. */
    public static function mapProduct(array $p): array
    {
        $variants = $p['variants'] ?? [];
        $flat = count($variants) <= 1 && (($variants[0]['title'] ?? 'Default Title') === 'Default Title');

        $mapped = [
            'external_id' => (string) $p['id'],
            'name' => $p['title'] ?? 'Untitled',
            'description' => isset($p['body_html']) ? trim(strip_tags($p['body_html'])) ?: null : null,
        ];

        if ($flat) {
            $v = $variants[0] ?? [];

            return $mapped + [
                'sku' => $v['sku'] ?? null,
                'quantity' => max(0, (int) ($v['inventory_quantity'] ?? 0)),
                'unit_price' => (float) ($v['price'] ?? 0),
            ];
        }

        $rows = array_map(fn ($v) => [
            'external_id' => (string) $v['id'],
            'name' => $v['title'] ?? 'Variant',
            'sku' => $v['sku'] ?? null,
            'quantity' => max(0, (int) ($v['inventory_quantity'] ?? 0)),
            'unit_price' => (float) ($v['price'] ?? 0),
        ], $variants);

        return $mapped + [
            'quantity' => array_sum(array_column($rows, 'quantity')),
            'unit_price' => $rows ? min(array_column($rows, 'unit_price')) : 0,
            'variations' => $rows,
        ];
    }

    /** Shopify order → CatalogSync order. Only variants we mirrored as variations carry a variation id. */
    public static function mapOrder(int $tenantId, array $o): array
    {
        $items = [];
        foreach ($o['line_items'] ?? [] as $li) {
            if (empty($li['product_id'])) {
                continue; // custom line item with no product behind it
            }
            $item = [
                'external_product_id' => (string) $li['product_id'],
                'quantity' => (int) $li['quantity'],
                'unit_price' => (float) $li['price'],
            ];
            if (! empty($li['variant_id']) && ProductVariation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_id', (string) $li['variant_id'])->exists()) {
                $item['external_variation_id'] = (string) $li['variant_id'];
            }
            $items[] = $item;
        }

        return [
            'external_order_id' => 'shopify:' . $o['id'],
            'notes' => 'Shopify order ' . ($o['name'] ?? $o['id']),
            'sale_date' => $o['created_at'] ?? now(),
            'items' => $items,
        ];
    }

    /** A product deleted in the store keeps its sales history here; it just stops being sellable. */
    public static function retireProduct(int $tenantId, string $externalId): void
    {
        Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_id', $externalId)->update(['quantity' => 0]);
    }
}
