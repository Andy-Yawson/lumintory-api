<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopOrder;
use App\Services\Shop\ShopOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The shop owner's side: settings, which products are listed, and incoming orders. */
class ShopController extends Controller
{
    /** The workspace's shop, created (switched off) the first time anyone looks. */
    private function shop(): Shop
    {
        $tenant = Auth::user()->tenant;

        return Shop::firstOrCreate(['tenant_id' => $tenant->id], [
            'slug' => Shop::suggestSlug($tenant->name), 'enabled' => false, 'in_marketplace' => true, 'pickup' => true, 'delivery' => false,
        ]);
    }

    private function admin(): void
    {
        abort_unless(Auth::user()->role === 'Administrator', 403, 'Only a workspace Administrator can change the shop.');
    }

    private function payload(Shop $shop): array
    {
        $tenantId = $shop->tenant_id;

        return $shop->only([
            'public_id', 'slug', 'enabled', 'in_marketplace', 'tagline', 'about', 'city', 'whatsapp', 'notify_phone',
            'hours', 'pickup', 'delivery', 'delivery_note', 'blocked_phones',
        ]) + [
            'banner_url' => $shop->bannerUrl(),
            'urls' => $shop->urls(),
            'live' => $shop->isLive(),
            'qualifies' => Shop::tenantQualifies($shop->tenant),
            'unlisted' => (bool) $shop->unlisted_at,
            'unlisted_reason' => $shop->unlisted_reason,
            'visible_products' => Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('shop_visible', true)->count(),
            'total_products' => Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'new_orders' => ShopOrder::where('status', 'new')->count(),
        ];
    }

    public function show()
    {
        return response()->json($this->payload($this->shop()));
    }

    public function update(Request $request)
    {
        $this->admin();
        $shop = $this->shop();
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'in_marketplace' => 'sometimes|boolean',
            'slug' => ['sometimes', 'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(Shop::RESERVED_SLUGS), Rule::unique('shops', 'slug')->ignore($shop->id)],
            'tagline' => 'sometimes|nullable|string|max:120',
            'about' => 'sometimes|nullable|string|max:2000',
            'city' => 'sometimes|nullable|string|max:80',
            'whatsapp' => 'sometimes|nullable|string|max:20',
            'notify_phone' => 'sometimes|nullable|string|max:20',
            'hours' => 'sometimes|nullable|string|max:120',
            'pickup' => 'sometimes|boolean',
            'delivery' => 'sometimes|boolean',
            'delivery_note' => 'sometimes|nullable|string|max:200',
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and single dashes, e.g. earles-closet.',
            'slug.not_in' => 'That link is reserved. Try another.',
            'slug.unique' => 'Another shop already uses that link.',
        ]);

        if (($data['enabled'] ?? false) && ! Shop::tenantQualifies($shop->tenant)) {
            return response()->json(['message' => 'The online shop is part of the Pro plan. Upgrade to switch it on.'], 403);
        }
        $pickup = $data['pickup'] ?? $shop->pickup;
        $delivery = $data['delivery'] ?? $shop->delivery;
        if (! $pickup && ! $delivery) {
            return response()->json(['message' => 'Offer pickup, delivery or both.', 'errors' => ['pickup' => ['Offer pickup, delivery or both.']]], 422);
        }

        $shop->fill($data)->save();

        return response()->json($this->payload($shop->fresh()));
    }

    public function uploadBanner(Request $request)
    {
        $this->admin();
        $request->validate(['banner' => 'required|file|mimes:png,jpg,jpeg,webp|max:5120']);
        $shop = $this->shop();
        $file = $request->file('banner');
        $path = "tenants/{$shop->tenant_id}/shop/banner-".Str::uuid().'.'.$file->extension();

        try {
            Storage::disk('media')->put($path, $file->getContent(), ['ContentType' => $file->getMimeType()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Image storage is unavailable right now. Please try again shortly.'], 503);
        }
        $old = $shop->banner_path;
        $shop->forceFill(['banner_path' => $path])->save();
        if ($old) {
            rescue(fn () => Storage::disk('media')->delete($old), report: true);
        }

        return response()->json($this->payload($shop));
    }

    public function removeBanner()
    {
        $this->admin();
        $shop = $this->shop();
        if ($shop->banner_path) {
            rescue(fn () => Storage::disk('media')->delete($shop->banner_path), report: true);
        }
        $shop->forceFill(['banner_path' => null])->save();

        return response()->json($this->payload($shop));
    }

    /** Show or hide products in the shop. `with_photos: true` lists every product that has a photo. */
    public function visibility(Request $request)
    {
        $this->admin();
        $tenantId = Auth::user()->tenant_id;
        $data = $request->validate([
            'ids' => 'array|max:1000',
            'ids.*' => 'integer',
            'with_photos' => 'boolean',
            'visible' => 'required|boolean',
        ]);

        $q = Product::withoutGlobalScopes()->where('tenant_id', $tenantId);
        if ($request->boolean('with_photos')) {
            $q->whereExists(fn ($e) => $e->selectRaw(1)->from('product_images')->whereColumn('product_images.product_id', 'products.id'));
        } else {
            $q->whereIn('id', $data['ids'] ?? []);
        }
        $changed = $q->update(['shop_visible' => $data['visible']]);
        \App\Services\Shop\ShopCache::bust(); // mass update fires no model events

        return response()->json(['changed' => $changed] + $this->payload($this->shop()));
    }

    public function blockPhone(Request $request)
    {
        $this->admin();
        $data = $request->validate(['phone' => 'required|string|max:20', 'blocked' => 'required|boolean']);
        $shop = $this->shop();
        $phone = ShopOrderService::normalisePhone($data['phone']);
        $list = collect($shop->blocked_phones ?? [])->reject(fn ($p) => substr(preg_replace('/\D/', '', $p), -9) === substr(preg_replace('/\D/', '', $phone), -9));
        if ($data['blocked']) {
            $list->push($phone);
        }
        $shop->forceFill(['blocked_phones' => $list->values()->all()])->save();

        return response()->json(['blocked_phones' => $shop->blocked_phones]);
    }

    // ---------------------------------------------------------------- orders

    public function orders(Request $request)
    {
        $q = ShopOrder::withCount('items')->latest();
        if ($s = $request->query('status')) {
            $s === 'open' ? $q->whereIn('status', ['new', 'contacted', 'confirmed']) : $q->where('status', $s);
        }
        if ($term = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('customer_name', 'like', "%{$term}%")->orWhere('customer_phone', 'like', "%{$term}%")->orWhere('number', ltrim($term, '#')));
        }
        $page = $q->paginate(20);

        return response()->json([
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'counts' => ShopOrder::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function order(int $id)
    {
        $order = ShopOrder::with('items')->findOrFail($id);
        $shop = $this->shop();

        return response()->json($order->toArray() + ['phone_blocked' => $shop->isBlocked($order->customer_phone)]);
    }

    public function updateOrder(Request $request, int $id)
    {
        $order = ShopOrder::findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'contacted', 'confirmed', 'cancelled'])]]);
        if ($order->status === 'completed') {
            return response()->json(['message' => 'This order was already turned into a sale.'], 422);
        }
        $order->status = $data['status'];
        if ($data['status'] === 'contacted' && ! $order->contacted_at) {
            $order->contacted_at = now();
        }
        $order->cancelled_at = $data['status'] === 'cancelled' ? now() : null;
        $order->save();

        return response()->json($order->load('items'));
    }

    public function convert(Request $request, int $id, ShopOrderService $service)
    {
        $order = ShopOrder::with('items')->findOrFail($id);
        $data = $request->validate(['payment_method' => 'required|string|max:40']);

        return response()->json($service->convert($order, $data['payment_method'])->load('items'));
    }
}
