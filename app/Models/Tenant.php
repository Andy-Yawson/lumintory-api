<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'plan',
        'settings',
        'subscription_ends_at',
        'referral_code',
        'referred_by_tenant_id',
        'is_active'
    ];

    protected $casts = [
        'settings' => 'array', // Cast JSON to array for easy access
    ];

    /** The Zinnvy green: used whenever a workspace hasn't picked its own colour. */
    public const DEFAULT_BRAND_COLOR = '#1f5a45';

    /**
     * The workspace's own branding, applied across the app and on receipts.
     * Not appended by default (signing the logo URL isn't free); call ->append('brand').
     */
    public function getBrandAttribute(): array
    {
        $settings = $this->settings ?? [];
        $logo = $settings['logo_path'] ?? null;
        $url = null;
        if ($logo) {
            $disk = \Illuminate\Support\Facades\Storage::disk('media');
            $url = rescue(fn () => config('filesystems.disks.media.url') ? $disk->url($logo) : $disk->temporaryUrl($logo, now()->addDay()), null, report: false);
        }

        return [
            'color' => $settings['brand_color'] ?? null,
            'logo_url' => $url,
        ];
    }

    // Relationship: A tenant has many products, sales, etc. (We'll add later)
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function referrer()
    {
        return $this->belongsTo(Tenant::class, 'referred_by_tenant_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_tenant_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->referral_code)) {
                $tenant->referral_code = self::generateUniqueReferralCode();
            }
        });
    }

    protected static function generateUniqueReferralCode()
    {
        do {
            $code = strtoupper(str()->random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function subscriptionHistories()
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    /** Public shop pages show this model: refresh the shop site's cache when it changes. */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\Shop\ShopCache::bust());
        static::deleted(fn () => \App\Services\Shop\ShopCache::bust());
    }
}
