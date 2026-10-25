<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Services\Shop\ShopCache;
use Illuminate\Database\Eloquent\Model;

class ShopService extends Model
{
    public const LOCATIONS = ['shop', 'home', 'both'];

    protected $guarded = ['id'];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    protected $casts = ['price' => 'float', 'price_from' => 'boolean', 'active' => 'boolean', 'product_ids' => 'array'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::saved(fn () => ShopCache::bust());
        static::deleted(fn () => ShopCache::bust());
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Shop::mediaUrl($this->image_path) : null;
    }
}
