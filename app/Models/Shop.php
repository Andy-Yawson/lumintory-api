<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A workspace's public shop on shop.zinnvy.com. Reached by its permanent public_id
 * (/shop/93923-33932-3939-2393) or its readable slug (/earles-closet).
 */
class Shop extends Model
{
    public const RESERVED_SLUGS = [
        'shop', 'shops', 'api', 'admin', 'www', 'zinnvy', 'login', 'signin', 'register', 'cart', 'checkout',
        'search', 'about', 'terms', 'privacy', 'help', 'support', 'orders', 'order', 'static', 'assets', 'p',
        'product', 'products', 'category', 'categories', 'sitemap', 'robots', 'favicon', 'report', 'sell', 'inventory',
        'feed', 'feeds', 'manifest', 'icon', 'apple-icon', 'opengraph-image', 'twitter-image', 'go', 'r', 'qr',
    ];

    protected $fillable = [
        'tenant_id', 'slug', 'enabled', 'in_marketplace', 'tagline', 'about', 'city', 'whatsapp',
        'notify_phone', 'hours', 'pickup', 'delivery', 'delivery_note',
        'momo_network', 'momo_number', 'momo_name', 'momo_merchant_id',
        'banner_focus_x', 'banner_focus_y', 'banner_zoom',
        'meta_pixel_id', 'ga_measurement_id', 'tiktok_pixel_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'in_marketplace' => 'boolean',
        'pickup' => 'boolean',
        'delivery' => 'boolean',
        'blocked_phones' => 'array',
        'unlisted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\Shop\ShopCache::bust());
        static::deleted(fn () => \App\Services\Shop\ShopCache::bust());
        static::creating(function (Shop $shop) {
            $shop->public_id ??= self::newPublicId();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function newPublicId(): string
    {
        do {
            $id = implode('-', [random_int(10000, 99999), random_int(10000, 99999), random_int(1000, 9999), random_int(1000, 9999)]);
        } while (self::where('public_id', $id)->exists());

        return $id;
    }

    /** "Earle’s Closet" → "earles-closet", made unique. */
    public static function suggestSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::limit(Str::slug(str_replace(['’', "'"], '', $name)), 34, '') ?: 'shop';
        if (strlen($base) < 3 || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = "{$base}-shop";
        }
        $slug = $base;
        $n = 2;
        while (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    public static function findByRef(string $ref): ?self
    {
        return preg_match('/^\d{5}-\d{5}-\d{4}-\d{4}$/', $ref)
            ? self::where('public_id', $ref)->first()
            : self::where('slug', strtolower($ref))->first();
    }

    /** Online shop is a Pro feature: any paid plan whose period hasn't run out. */
    public static function tenantQualifies(?Tenant $tenant): bool
    {
        return $tenant
            && $tenant->is_active
            && strtolower((string) $tenant->plan) !== 'basic'
            && (! $tenant->subscription_ends_at || now()->lt($tenant->subscription_ends_at));
    }

    public function isLive(): bool
    {
        return $this->enabled && ! $this->unlisted_at && self::tenantQualifies($this->tenant);
    }

    /** Same rule as isLive(), in SQL, for marketplace listings. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('shops.enabled', true)
            ->whereNull('shops.unlisted_at')
            ->whereHas('tenant', fn ($t) => $t->where('is_active', true)
                ->whereRaw('LOWER(plan) != ?', ['basic'])
                ->where(fn ($w) => $w->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', now())));
    }

    public function isBlocked(string $phone): bool
    {
        $last9 = substr(preg_replace('/\D/', '', $phone), -9);

        return collect($this->blocked_phones ?? [])->contains(fn ($p) => substr(preg_replace('/\D/', '', $p), -9) === $last9);
    }

    public const MOMO_NETWORKS = ['MTN', 'Telecel', 'AirtelTigo'];

    /**
     * Mobile Money details for paying this shop, or null if not set up. Shown to a customer only
     * after they place an order (not on public pages), always with "pay after the shop confirms".
     */
    public function momo(): ?array
    {
        if (! $this->momo_number && ! $this->momo_merchant_id) {
            return null;
        }

        return array_filter([
            'network' => $this->momo_network,
            'number' => $this->momo_number,
            'name' => $this->momo_name,
            'merchant_id' => $this->momo_merchant_id,
        ]);
    }

    /** How to frame the banner: focus point in % and zoom in % (100 = fit). */
    public function bannerFraming(): array
    {
        return ['x' => (int) ($this->banner_focus_x ?? 50), 'y' => (int) ($this->banner_focus_y ?? 50), 'zoom' => (int) ($this->banner_zoom ?? 100)];
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? self::mediaUrl($this->banner_path) : null;
    }

    public static function mediaUrl(string $path): ?string
    {
        $disk = Storage::disk('media');

        return rescue(fn () => config('filesystems.disks.media.url') ? $disk->url($path) : $disk->temporaryUrl($path, now()->addDay()), null, report: false);
    }

    public function urls(): array
    {
        $base = rtrim((string) config('services.shop.url', 'https://shop.zinnvy.com'), '/');

        return [
            'permanent' => "{$base}/shop/{$this->public_id}",
            'readable' => $this->slug ? "{$base}/{$this->slug}" : null,
        ];
    }
}
