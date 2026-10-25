<?php

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\Shop;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the public may see. Only shop_visible products of a live shop, and only
 * safe fields: never cost, SKU, exact stock counts, notes or internal ids beyond the product id.
 */
class ShopCatalog
{
    /** Exact stock is shown only when it's this low ("Only 3 left"). */
    public const SHOW_LEFT_AT = 5;

    public function shopPayload(Shop $shop): array
    {
        $tenant = $shop->tenant;
        $settings = $tenant->settings ?? [];

        return [
            'id' => $shop->public_id,
            'slug' => $shop->slug,
            'name' => $tenant->name,
            'tagline' => $shop->tagline,
            'about' => $shop->about,
            'city' => $shop->city,
            'hours' => $shop->hours,
            'whatsapp' => $shop->whatsapp,
            'pickup' => $shop->pickup,
            'delivery' => $shop->delivery,
            'delivery_note' => $shop->delivery_note,
            // The details themselves are only revealed after an order is placed.
            'accepts_momo' => (bool) $shop->momo(),
            'takes_bookings' => $shop->bookings_enabled && \App\Models\ShopService::withoutGlobalScopes()->where('shop_id', $shop->id)->where('active', true)->exists(),
            // The owner's own pixels: loaded on their shop pages alongside Zinnvy's.
            'tracking' => array_filter(['meta' => $shop->meta_pixel_id, 'ga' => $shop->ga_measurement_id, 'tiktok' => $shop->tiktok_pixel_id]) ?: null,
            'banner_url' => $shop->bannerUrl(),
            'banner_framing' => $shop->bannerFraming(),
            'brand' => $tenant->brand,
            'currency' => $settings['currency'] ?? 'GHS',
            'currency_symbol' => $settings['currency_symbol'] ?? 'GH₵',
            'urls' => $shop->urls(),
            'categories' => $this->visible($tenant->id)->whereNotNull('category_id')->with('category:id,name')->get()
                ->pluck('category.name')->filter()->unique()->sort()->values(),
        ];
    }

    /** Base query: one workspace's products that are switched on for the shop. */
    public function visible(int $tenantId): Builder
    {
        return Product::withoutGlobalScopes()->where('products.tenant_id', $tenantId)->where('shop_visible', true);
    }

    public function withRelations(Builder $q): Builder
    {
        return $q->with([
            'images' => fn ($i) => $i->withoutGlobalScopes()->orderBy('position'),
            'videos' => fn ($v) => $v->where('status', 'ready'),
            'variations' => fn ($v) => $v->withoutGlobalScopes()->orderBy('id'),
            'category' => fn ($c) => $c->withoutGlobalScopes()->select('id', 'name'),
        ]);
    }

    public function productPayload(Product $p, bool $full = false, ?array $shopInfo = null): array
    {
        // Old products have one unnamed list of variations: present it as a single "Option".
        $optionNames = $p->option_names ?: ($p->variations->isNotEmpty() ? ['Option'] : []);
        $variations = $p->variations->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'options' => $v->options ?: [$optionNames[0] ?? 'Option' => $v->name],
            'price' => (float) ($v->unit_price ?: $p->unit_price),
        ] + self::availability((float) $v->quantity))->values();

        $prices = $variations->isNotEmpty() ? $variations->pluck('price') : collect([(float) $p->unit_price]);
        $stock = $variations->isNotEmpty() ? (float) $p->variations->sum('quantity') : (float) $p->quantity;
        $images = $p->images->map(fn ($i) => $i->url)->filter()->values();
        $videos = $p->relationLoaded('videos') ? $p->videos->filter(fn ($v) => $v->url)->map(fn ($v) => [
            'url' => $v->url, 'poster' => $v->poster_url, 'duration' => $v->duration, 'width' => $v->width, 'height' => $v->height,
        ])->values() : collect();

        return array_filter([
            'id' => $p->id,
            'name' => $p->name,
            'size' => $p->size,
            'category' => $p->category?->name,
            'price' => $prices->min(),
            'price_max' => $prices->max() > $prices->min() ? $prices->max() : null,
            'images' => $full ? $images : null,
            'videos' => $full && $videos->isNotEmpty() ? $videos : null,
            'has_video' => $videos->isNotEmpty() ?: null,
            // No photo yet but a video: use its poster as the tile image.
            'image' => $images->first() ?? $videos->first()['poster'] ?? null,
            'description' => $full ? $p->description : null,
            'variations' => $full || $variations->isNotEmpty() ? $variations : null,
            // [{name: "Colour", values: ["Brown", "Black"]}, {name: "Size", values: [...]}], values in the owner's order
            'options' => $variations->isNotEmpty() ? collect($optionNames)->map(fn ($n) => [
                'name' => $n,
                'values' => $variations->pluck("options.{$n}")->filter(fn ($x) => $x !== null && $x !== '')->unique()->values(),
            ])->values() : null,
            'shop' => $shopInfo,
        ] + self::availability($stock), fn ($v) => $v !== null);
    }

    /** in_stock | low (with "left") | sold_out */
    public static function availability(float $qty): array
    {
        if ($qty <= 0) {
            return ['availability' => 'sold_out'];
        }
        if ($qty <= self::SHOW_LEFT_AT) {
            return ['availability' => 'low', 'left' => (int) floor($qty)];
        }

        return ['availability' => 'in_stock'];
    }

    /** Free-text search over name, size and category. */
    public static function search(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('products.name', 'like', $like)
            ->orWhere('products.size', 'like', $like)
            ->orWhereHas('category', fn ($c) => $c->withoutGlobalScopes()->where('name', 'like', $like)));
    }

    public static function shopCard(Shop $shop, Tenant $tenant): array
    {
        return [
            'id' => $shop->public_id,
            'slug' => $shop->slug,
            'name' => $tenant->name,
            'tagline' => $shop->tagline,
            'city' => $shop->city,
            'logo_url' => $tenant->brand['logo_url'],
            'color' => $tenant->brand['color'],
        ];
    }
}
