<?php

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Shop;
use App\Models\ShopEvent;
use App\Models\ShopOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shop Insights: visitors, funnel, products, sources, searches and plain-language highlights,
 * from anonymous shop events plus the shop's orders. Everything compares to the previous period.
 */
class ShopInsights
{
    public const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    /** Where a visit came from, from utm_source / click ids / referrer host. */
    public static function classifySource(?string $utmSource, ?string $referrer, bool $fromMarketplace = false, ?string $clickId = null): string
    {
        $s = strtolower(trim((string) $utmSource));
        $host = strtolower((string) parse_url((string) $referrer, PHP_URL_HOST));
        $hay = $s.' '.$host;
        $map = [
            'whatsapp' => ['whatsapp', 'wa.me', 'wa_'],
            'instagram' => ['instagram', 'ig'],
            'facebook' => ['facebook', 'fb', 'messenger'],
            'tiktok' => ['tiktok'],
            'google' => ['google'],
            'x' => ['twitter', 't.co', 'x.com'],
        ];
        foreach ($map as $source => $needles) {
            foreach ($needles as $n) {
                if ($s === $n || str_contains($hay, $n)) {
                    return $source;
                }
            }
        }
        if ($clickId === 'fbclid') {
            return 'facebook';
        }
        if ($clickId === 'gclid') {
            return 'google';
        }
        if ($clickId === 'ttclid') {
            return 'tiktok';
        }
        if ($fromMarketplace || str_contains($host, 'shop.zinnvy.com')) {
            return 'marketplace';
        }

        return $s === '' && $host === '' ? 'direct' : 'other';
    }

    public static function device(?string $ua): string
    {
        $ua = strtolower((string) $ua);
        if (str_contains($ua, 'ipad') || (str_contains($ua, 'android') && ! str_contains($ua, 'mobile')) || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        return preg_match('/mobile|iphone|android|opera mini|kaios/', $ua) ? 'mobile' : 'desktop';
    }

    public static function isBot(?string $ua): bool
    {
        return $ua === null || $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp\/|preview|headless|lighthouse|pingdom|uptime|curl|wget|python|axios|node-fetch|go-http/i', $ua);
    }

    public function build(Shop $shop, string $range = '30d'): array
    {
        $days = self::RANGES[$range] ?? 30;
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subDays($days - 1)->startOfDay();
        $prevFrom = $from->subDays($days);
        $prevTo = $from->subSecond();

        $cur = $this->totals($shop, $from, $to);
        $prev = $this->totals($shop, $prevFrom, $prevTo);
        $products = $this->products($shop, $from, $to);
        $sources = $this->sources($shop, $from, $to);
        $searches = $this->searches($shop, $from, $to);

        return [
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $cur,
            'previous' => $prev,
            'daily' => $this->daily($shop, $from, $to, $days),
            'funnel' => [
                ['step' => 'Visited your shop', 'count' => $cur['visitors']],
                ['step' => 'Viewed a product', 'count' => $cur['product_viewers']],
                ['step' => 'Added to cart', 'count' => $cur['carters']],
                ['step' => 'Started checkout', 'count' => $cur['checkouts']],
                ['step' => 'Placed an order', 'count' => $cur['ordering_visitors']],
            ],
            'products' => $products,
            'sources' => $sources,
            'searches' => $searches,
            'devices' => $this->devices($shop, $from, $to),
            'highlights' => $this->highlights($shop, $cur, $prev, $products, $sources, $searches),
        ];
    }

    private function events(Shop $shop, $from, $to)
    {
        return ShopEvent::where('shop_id', $shop->id)->whereBetween('created_at', [$from, $to]);
    }

    private function orders(Shop $shop, $from, $to)
    {
        return ShopOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->where('status', '!=', 'cancelled')->whereBetween('created_at', [$from, $to]);
    }

    public function totals(Shop $shop, $from, $to): array
    {
        $e = fn () => $this->events($shop, $from, $to);
        $distinct = fn (string $type) => (int) $e()->where('type', $type)->distinct()->count('visitor_id');
        $visitors = (int) $e()->distinct()->count('visitor_id');
        $orders = (int) $this->orders($shop, $from, $to)->count();
        // Funnel and conversion only count orders we can tie to a tracked visit (older orders can't be).
        $orderingVisitors = (int) $this->orders($shop, $from, $to)->whereNotNull('visitor_id')->distinct()->count('visitor_id');

        return [
            'visitors' => $visitors,
            'page_views' => (int) $e()->whereIn('type', ['view', 'product_view'])->count(),
            'product_views' => (int) $e()->where('type', 'product_view')->count(),
            'product_viewers' => $distinct('product_view'),
            'carters' => $distinct('add_to_cart'),
            'checkouts' => $distinct('checkout'),
            'orders' => $orders,
            'order_value' => round((float) $this->orders($shop, $from, $to)->sum('subtotal'), 2),
            'shares' => (int) $e()->where('type', 'share')->count(),
            'chats' => (int) $e()->where('type', 'chat')->count(),
            'bookings' => (int) \App\Models\Booking::withoutGlobalScopes()->where('shop_id', $shop->id)->whereBetween('created_at', [$from, $to])->count(),
            'ordering_visitors' => $orderingVisitors,
            'conversion' => $visitors ? round(min($orderingVisitors, $visitors) / $visitors * 100, 1) : 0.0,
        ];
    }

    private function daily(Shop $shop, $from, $to, int $days): array
    {
        $visitors = $this->events($shop, $from, $to)->selectRaw('DATE(created_at) d, COUNT(DISTINCT visitor_id) n')->groupBy('d')->pluck('n', 'd');
        $views = $this->events($shop, $from, $to)->where('type', 'product_view')->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');
        $orders = $this->orders($shop, $from, $to)->selectRaw('DATE(created_at) d, COUNT(*) n')->groupBy('d')->pluck('n', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = CarbonImmutable::parse($from)->addDays($i)->toDateString();
            $out[] = ['date' => $d, 'visitors' => (int) ($visitors[$d] ?? 0), 'product_views' => (int) ($views[$d] ?? 0), 'orders' => (int) ($orders[$d] ?? 0)];
        }

        return $out;
    }

    private function products(Shop $shop, $from, $to): array
    {
        $views = $this->events($shop, $from, $to)->where('type', 'product_view')->whereNotNull('product_id')->selectRaw('product_id, COUNT(*) n')->groupBy('product_id')->pluck('n', 'product_id');
        $carts = $this->events($shop, $from, $to)->where('type', 'add_to_cart')->whereNotNull('product_id')->selectRaw('product_id, COUNT(*) n')->groupBy('product_id')->pluck('n', 'product_id');
        $ordered = DB::table('shop_order_items')->join('shop_orders', 'shop_orders.id', '=', 'shop_order_items.shop_order_id')
            ->where('shop_orders.shop_id', $shop->id)->where('shop_orders.status', '!=', 'cancelled')->whereBetween('shop_orders.created_at', [$from, $to])
            ->whereNotNull('shop_order_items.product_id')
            ->selectRaw('shop_order_items.product_id, COUNT(DISTINCT shop_orders.id) n')->groupBy('shop_order_items.product_id')->pluck('n', 'product_id');

        $ids = collect($views->keys())->merge($carts->keys())->merge($ordered->keys())->unique();
        $products = Product::withoutGlobalScopes()->where('tenant_id', $shop->tenant_id)->whereIn('id', $ids)->get(['id', 'name', 'quantity']);
        $variationStock = ProductVariation::withoutGlobalScopes()->whereIn('product_id', $ids)->selectRaw('product_id, SUM(quantity) q, COUNT(*) c')->groupBy('product_id')->get()->keyBy('product_id');

        return $products->map(function ($p) use ($views, $carts, $ordered, $variationStock) {
            $stock = isset($variationStock[$p->id]) ? (float) $variationStock[$p->id]->q : (float) $p->quantity;
            $v = (int) ($views[$p->id] ?? 0);
            $o = (int) ($ordered[$p->id] ?? 0);

            return ['id' => $p->id, 'name' => $p->name, 'views' => $v, 'carts' => (int) ($carts[$p->id] ?? 0), 'orders' => $o,
                'conversion' => $v ? round($o / $v * 100, 1) : 0.0, 'in_stock' => $stock > 0];
        })->sortByDesc('views')->values()->take(50)->all();
    }

    private function sources(Shop $shop, $from, $to): array
    {
        // A visitor's source is where their first visit in the period came from.
        $firsts = $this->events($shop, $from, $to)->selectRaw('visitor_id, MIN(id) first_id')->groupBy('visitor_id');
        $bySource = ShopEvent::joinSub($firsts, 'f', 'f.first_id', '=', 'shop_events.id')
            ->selectRaw('shop_events.source, COUNT(*) n')->groupBy('shop_events.source')->pluck('n', 'source');

        $orders = $this->orders($shop, $from, $to)->get(['source']);
        $ordersBySource = $orders->countBy(function ($o) {
            $s = $o->source ?? [];
            $click = collect(['fbclid', 'gclid', 'ttclid'])->first(fn ($k) => ! empty($s[$k]));

            return self::classifySource($s['utm_source'] ?? null, $s['referrer'] ?? null, false, $click);
        });

        return collect(ShopEvent::SOURCES)->map(fn ($s) => ['source' => $s, 'visitors' => (int) ($bySource[$s] ?? 0), 'orders' => (int) ($ordersBySource[$s] ?? 0)])
            ->filter(fn ($r) => $r['visitors'] || $r['orders'])->sortByDesc('visitors')->values()->all();
    }

    private function searches(Shop $shop, $from, $to): array
    {
        return $this->events($shop, $from, $to)->where('type', 'search')->whereNotNull('search')
            ->selectRaw('LOWER(search) term, COUNT(*) n, MIN(results) min_results')->groupBy('term')->orderByDesc('n')->limit(20)->get()
            ->map(fn ($r) => ['term' => $r->term, 'count' => (int) $r->n, 'no_results' => (int) $r->min_results === 0])->all();
    }

    private function devices(Shop $shop, $from, $to): array
    {
        $rows = $this->events($shop, $from, $to)->selectRaw('device, COUNT(DISTINCT visitor_id) n')->groupBy('device')->pluck('n', 'device');

        return collect(['mobile', 'tablet', 'desktop'])->map(fn ($d) => ['device' => $d, 'visitors' => (int) ($rows[$d] ?? 0)])->filter(fn ($r) => $r['visitors'])->values()->all();
    }

    /** Plain-language takeaways, most useful first. Rules, not guesses: each one is backed by the numbers above. */
    private function highlights(Shop $shop, array $cur, array $prev, array $products, array $sources, array $searches): array
    {
        $out = [];
        $pct = fn ($a, $b) => $b ? round(($a - $b) / $b * 100) : null;
        $label = fn ($s) => ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'google' => 'Google', 'x' => 'X (Twitter)', 'marketplace' => 'the Zinnvy marketplace', 'direct' => 'people typing your link or opening it directly', 'other' => 'other websites'][$s] ?? $s;

        if ($cur['visitors'] === 0) {
            return [['tone' => 'info', 'text' => 'No visitors yet in this period. Share your shop link on WhatsApp status and Instagram to get started: use the tagged links under Promote your shop so you can see which works.']];
        }

        $change = $pct($cur['visitors'], $prev['visitors']);
        if ($change !== null && abs($change) >= 10) {
            $out[] = ['tone' => $change > 0 ? 'good' : 'warn', 'text' => sprintf('Visitors are %s %d%% on the previous period (%d vs %d).', $change > 0 ? 'up' : 'down', abs($change), $cur['visitors'], $prev['visitors'])];
        } elseif ($prev['visitors'] === 0) {
            $out[] = ['tone' => 'good', 'text' => "{$cur['visitors']} people visited your shop in this period."];
        }

        if ($top = $sources[0] ?? null) {
            $share = round($top['visitors'] / max(1, $cur['visitors']) * 100);
            if ($top['source'] !== 'direct' && $share >= 30) {
                $out[] = ['tone' => 'info', 'text' => sprintf('%d%% of your visitors came from %s%s.', $share, $label($top['source']), $top['orders'] ? sprintf(', bringing %d order%s', $top['orders'], $top['orders'] === 1 ? '' : 's') : '')];
            }
        }

        foreach ($products as $p) {
            if ($p['views'] >= 10 && $p['orders'] === 0) {
                $out[] = ['tone' => 'warn', 'text' => $p['in_stock']
                    ? sprintf('"%s" was viewed %d times but not ordered. Better photos, a video or a price check could help.', $p['name'], $p['views'])
                    : sprintf('"%s" was viewed %d times but is out of stock. Restock it: people are looking.', $p['name'], $p['views'])];
                break;
            }
        }

        $missing = collect($searches)->where('no_results', true)->take(3)->pluck('term');
        if ($missing->isNotEmpty()) {
            $out[] = ['tone' => 'info', 'text' => 'Visitors searched for '.$missing->map(fn ($t) => "\"{$t}\"")->join(', ', ' and ').' and found nothing. Worth stocking or listing?'];
        }

        if ($cur['carters'] >= 5 && $cur['ordering_visitors'] <= $cur['carters'] * 0.2) {
            $out[] = ['tone' => 'warn', 'text' => "{$cur['carters']} people added to cart but only {$cur['ordering_visitors']} ordered. Make sure delivery options and your WhatsApp number are clear on your shop."];
        }

        if ($cur['ordering_visitors'] > 0 && $cur['conversion'] >= 2) {
            $out[] = ['tone' => 'good', 'text' => "{$cur['conversion']}% of visitors placed an order. That's a healthy rate for an online shop."];
        }

        return array_slice($out, 0, 5);
    }
}
