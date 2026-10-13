<?php

namespace App\Http\Controllers\Api;

use App\Helpers\MailHelper;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\Shop\ShopCatalog;
use App\Services\Shop\ShopOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Unauthenticated API behind shop.zinnvy.com. Every query is filtered to live shops and
 * shop_visible products explicitly (the tenant scope doesn't apply without a signed-in user).
 */
class PublicShopController extends Controller
{
    public function __construct(private ShopCatalog $catalog) {}

    private function liveShop(string $ref): Shop
    {
        $shop = Shop::findByRef($ref);
        abort_unless($shop && $shop->isLive(), 404, 'Shop not found.');

        return $shop;
    }

    public function shop(string $ref)
    {
        return response()->json($this->catalog->shopPayload($this->liveShop($ref)));
    }

    public function products(Request $request, string $ref)
    {
        $shop = $this->liveShop($ref);
        $q = $this->catalog->withRelations(ShopCatalog::search($this->catalog->visible($shop->tenant_id), $request->query('search')));
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->withoutGlobalScopes()->where('name', $cat));
        }
        $page = $this->sorted($q, $request->query('sort'))->paginate(min(48, max(1, (int) $request->query('per_page', 24))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => $this->catalog->productPayload($p)),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function product(string $ref, int $id)
    {
        $shop = $this->liveShop($ref);
        $product = $this->catalog->withRelations($this->catalog->visible($shop->tenant_id))->findOrFail($id);

        return response()->json($this->catalog->productPayload($product, full: true));
    }

    public function placeOrder(Request $request, string $ref, ShopOrderService $orders)
    {
        $shop = $this->liveShop($ref);

        // Honeypot: a field people never see. Bots fill it; pretend all went well.
        if (filled($request->input('website'))) {
            return response()->json(['number' => random_int(1001, 1999), 'status' => 'new'], 201);
        }

        $data = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:120',
            'fulfilment' => 'required|in:pickup,delivery',
            'address' => 'required_if:fulfilment,delivery|nullable|string|max:300',
            'note' => 'nullable|string|max:500',
            'consent' => 'accepted',
            'items' => 'required|array|min:1|max:30',
            'items.*.product_id' => 'required|integer',
            'items.*.variation_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:1|max:999',
            'turnstile_token' => 'nullable|string',
        ], [
            'consent.accepted' => 'Please agree to share your details with the shop.',
            'address.required_if' => 'Add a delivery address.',
        ]);

        if (! ShopOrderService::phoneLooksValid($data['phone'])) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
        }
        $this->checkTurnstile($data['turnstile_token'] ?? null, $request->ip());

        $order = $orders->place($shop, $data, $request->ip());

        return response()->json([
            'number' => $order->number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'shop' => $shop->tenant->name,
            'phone' => $order->customer_phone,
        ], 201);
    }

    public function report(Request $request, string $ref)
    {
        $shop = Shop::findByRef($ref);
        abort_unless($shop, 404);
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'contact' => 'nullable|string|max:120',
        ]);

        $tenant = $shop->tenant;
        foreach (config('services.shop.report_emails', []) as $to) {
            MailHelper::sendEmailNotification($to, "Shop report: {$tenant->name} (#{$tenant->id})",
                "Shop: {$tenant->name} ({$shop->public_id}, /{$shop->slug})\nWorkspace id: {$tenant->id}\n"
                .'Reporter contact: '.($data['contact'] ?? 'not given')."\n\n{$data['reason']}\n\n"
                ."To unlist it: Zinnvy Admin → Tenants → {$tenant->name} → Shop.");
        }

        return response()->json(['message' => 'Thanks. Our team will review this shop.']);
    }

    // ---------------------------------------------------------------- marketplace

    public function marketplaceProducts(Request $request)
    {
        $q = ShopCatalog::search(
            \App\Models\Product::withoutGlobalScopes()->where('shop_visible', true)
                ->whereIn('products.tenant_id', $this->marketplaceTenantIds()),
            $request->query('search'),
        );
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->withoutGlobalScopes()->where('name', $cat));
        }
        // Products with a photo first: the marketplace is visual.
        $q->orderByRaw('EXISTS (SELECT 1 FROM product_images WHERE product_images.product_id = products.id) DESC');
        $page = $this->sorted($this->catalog->withRelations($q)->with('tenant'), $request->query('sort'))
            ->paginate(min(48, max(1, (int) $request->query('per_page', 24))));

        $shops = Shop::whereIn('tenant_id', collect($page->items())->pluck('tenant_id')->unique())->get()->keyBy('tenant_id');

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => $this->catalog->productPayload($p, shopInfo: [
                'id' => $shops[$p->tenant_id]->public_id,
                'slug' => $shops[$p->tenant_id]->slug,
                'name' => $p->tenant->name,
                'currency_symbol' => $p->tenant->settings['currency_symbol'] ?? 'GH₵',
            ])),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function marketplaceShops(Request $request)
    {
        $term = trim((string) $request->query('search'));
        $shops = Shop::live()->where('in_marketplace', true)->with('tenant')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$term}%"))
                ->orWhere('city', 'like', "%{$term}%")->orWhere('tagline', 'like', "%{$term}%")))
            ->whereExists(fn ($e) => $e->selectRaw(1)->from('products')->whereColumn('products.tenant_id', 'shops.tenant_id')->where('shop_visible', true))
            ->latest('updated_at')->limit(60)->get();

        return response()->json(['data' => $shops->map(fn ($s) => ShopCatalog::shopCard($s, $s->tenant))]);
    }

    public function marketplaceCategories()
    {
        $names = \App\Models\Category::withoutGlobalScopes()
            ->whereIn('tenant_id', $this->marketplaceTenantIds())
            ->whereExists(fn ($e) => $e->selectRaw(1)->from('products')->whereColumn('products.category_id', 'categories.id')->where('shop_visible', true))
            ->pluck('name')->map(fn ($n) => trim($n))->filter();

        // Group "Shoes" / "shoes" together and show the most used spelling.
        return response()->json(['data' => $names->groupBy(fn ($n) => mb_strtolower($n))
            ->sortByDesc(fn ($g) => $g->count())->take(16)->map(fn ($g) => $g->countBy()->sortDesc()->keys()->first())->values()]);
    }

    /** Ids for a sitemap: every live shop and its visible products. */
    public function sitemap()
    {
        $shops = Shop::live()->get(['id', 'tenant_id', 'public_id', 'slug', 'updated_at']);

        return response()->json(['shops' => $shops->map(fn ($s) => [
            'ref' => $s->slug ?? $s->public_id,
            'updated_at' => $s->updated_at?->toIso8601String(),
            'products' => \App\Models\Product::withoutGlobalScopes()->where('tenant_id', $s->tenant_id)->where('shop_visible', true)->limit(2000)->pluck('id'),
        ])]);
    }

    public function config()
    {
        return response()->json(['turnstile_site_key' => config('services.turnstile.site_key') ?: null]);
    }

    private function marketplaceTenantIds()
    {
        return Shop::live()->where('in_marketplace', true)->pluck('tenant_id');
    }

    private function sorted($q, ?string $sort)
    {
        return match ($sort) {
            'price_asc' => $q->orderBy('unit_price'),
            'price_desc' => $q->orderByDesc('unit_price'),
            'name' => $q->orderBy('name'),
            default => $q->orderByDesc('products.updated_at'),
        };
    }

    private function checkTurnstile(?string $token, ?string $ip): void
    {
        $secret = config('services.turnstile.secret');
        if (! $secret) {
            return; // not configured yet: honeypot + rate limits only
        }
        $ok = $token && rescue(fn () => Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret, 'response' => $token, 'remoteip' => $ip,
        ])->json('success') === true, false, report: false);

        if (! $ok) {
            throw ValidationException::withMessages(['turnstile_token' => 'Please confirm you\'re not a robot and try again.']);
        }
    }
}
