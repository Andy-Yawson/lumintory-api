<?php

namespace App\Services\Integrations;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\StoreConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WooCommerce over its REST API. Connection is either the built-in
 * /wc-auth/v1/authorize approval screen (WooCommerce POSTs generated keys to
 * our callback) or keys the owner pastes in. Webhooks are signed with a
 * per-connection secret we generate.
 */
class WooCommerceService
{
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'product.created', 'product.updated', 'product.deleted'];
    private const PAID_STATUSES = ['processing', 'completed', 'on-hold'];

    /** "my-store.com/" / "http://x.com/shop" → "https://x.com/shop" (https only: keys must not travel in clear). */
    public static function normaliseUrl(string $input): ?string
    {
        $u = trim($input);
        if ($u === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        $parts = parse_url($u);
        if (! $parts || empty($parts['host']) || ! str_contains($parts['host'], '.')) {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($parts['path'] ?? '', '/');
    }

    /** Where WooCommerce shows its "Approve access for Zinnvy" screen. */
    public function authorizeUrl(string $storeUrl, string $state, string $returnUrl): string
    {
        return $storeUrl . '/wc-auth/v1/authorize?' . http_build_query([
            'app_name' => 'Zinnvy Inventory',
            'scope' => 'read_write',
            'user_id' => $state,
            'return_url' => $returnUrl,
            'callback_url' => url('/api/v1/stores/woocommerce/callback'),
        ]);
    }

    private function api(StoreConnection $c): PendingRequest
    {
        return Http::withBasicAuth((string) $c->credential('consumer_key'), (string) $c->credential('consumer_secret'))
            ->acceptJson()->timeout(30)
            ->baseUrl($c->store_url . '/wp-json/wc/v3');
    }

    /** Cheap authenticated call to prove the keys work. */
    public function verify(StoreConnection $c): bool
    {
        return $this->api($c)->get('/products', ['per_page' => 1])->successful();
    }

    public function registerWebhooks(StoreConnection $c): void
    {
        $delivery = url('/api/v1/webhooks/woocommerce/' . $c->uuid);
        foreach (self::WEBHOOK_TOPICS as $topic) {
            $this->api($c)->post('/webhooks', [
                'name' => "Zinnvy {$topic}",
                'topic' => $topic,
                'delivery_url' => $delivery,
                'secret' => $c->credential('webhook_secret'),
            ]);
        }
    }

    public static function newWebhookSecret(): string
    {
        return Str::random(48);
    }

    public function validWebhookSignature(StoreConnection $c, string $rawBody, ?string $header): bool
    {
        if (! $header) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, (string) $c->credential('webhook_secret'), true)), $header);
    }

    /** Variable products need a second call for their variations. */
    public function withVariations(StoreConnection $c, array $p): array
    {
        if (($p['type'] ?? '') === 'variable') {
            $v = $this->api($c)->get("/products/{$p['id']}/variations", ['per_page' => 100]);
            $p['_variations'] = $v->successful() ? $v->json() : [];
        }

        return $p;
    }

    /** Every product (pages of 100), variations fetched for variable products. */
    public function products(StoreConnection $c): \Generator
    {
        $page = 1;
        do {
            $res = $this->api($c)->get('/products', ['per_page' => 100, 'page' => $page, 'status' => 'publish']);
            $res->throw();
            foreach ($res->json() as $p) {
                yield $this->withVariations($c, $p);
            }
            $totalPages = (int) $res->header('X-WP-TotalPages');
            $page++;
        } while ($page <= $totalPages);
    }

    /** Woo product → CatalogSync product. Stock that Woo doesn't manage reads as 0 here. */
    public static function mapProduct(array $p): array
    {
        $qty = fn (array $x) => max(0, (int) ($x['stock_quantity'] ?? 0));

        $mapped = [
            'external_id' => (string) $p['id'],
            'name' => $p['name'] ?? 'Untitled',
            'sku' => $p['sku'] ?: null,
            'description' => isset($p['short_description']) ? (trim(strip_tags($p['short_description'])) ?: null) : null,
        ];

        if (empty($p['_variations'])) {
            return $mapped + ['quantity' => $qty($p), 'unit_price' => (float) ($p['price'] ?: 0)];
        }

        $rows = array_map(function ($v) use ($qty, $p) {
            $attrs = collect($v['attributes'] ?? [])->pluck('option')->filter()->implode(' / ');

            return [
                'external_id' => (string) $v['id'],
                'name' => $attrs ?: ($v['sku'] ?: 'Variant ' . $v['id']),
                'sku' => $v['sku'] ?: null,
                'quantity' => $qty($v),
                'unit_price' => (float) ($v['price'] ?: ($p['price'] ?: 0)),
            ];
        }, $p['_variations']);

        return $mapped + [
            'quantity' => array_sum(array_column($rows, 'quantity')),
            'unit_price' => min(array_column($rows, 'unit_price')),
            'variations' => $rows,
        ];
    }

    /** Woo order → CatalogSync order, or null while it isn't paid for yet (pending/cancelled/failed/refunded). */
    public static function mapOrder(int $tenantId, array $o): ?array
    {
        if (! in_array($o['status'] ?? '', self::PAID_STATUSES, true)) {
            return null;
        }

        $items = [];
        foreach ($o['line_items'] ?? [] as $li) {
            if (empty($li['product_id'])) {
                continue;
            }
            $qty = (int) $li['quantity'];
            $item = [
                'external_product_id' => (string) $li['product_id'],
                'quantity' => $qty,
                // line "price" is the unit price; fall back to total/qty
                'unit_price' => (float) ($li['price'] ?? ($qty ? $li['total'] / $qty : 0)),
            ];
            if (! empty($li['variation_id']) && ProductVariation::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_id', (string) $li['variation_id'])->exists()) {
                $item['external_variation_id'] = (string) $li['variation_id'];
            }
            $items[] = $item;
        }

        return [
            'external_order_id' => 'woocommerce:' . $o['id'],
            'notes' => 'WooCommerce order #' . ($o['number'] ?? $o['id']),
            'sale_date' => $o['date_created'] ?? now(),
            'items' => $items,
        ];
    }

    public static function retireProduct(int $tenantId, string $externalId): void
    {
        Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('external_id', $externalId)->update(['quantity' => 0]);
    }
}
