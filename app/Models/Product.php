<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'size',
        'quantity',
        'unit_price',
        'description',
        'external_id',
        'sku',
        'lead_time_days',
        'min_stock_threshold',
        'category_id',
        'option_names',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'lead_time_days' => 'integer',
        // Listed on the online shop. Changed only through /shop/visibility, so not fillable.
        'shop_visible' => 'boolean',
        'option_names' => 'array',
    ];

    protected $appends = [
        'computed_quantity',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);

        static::creating(function ($product) {
            if (Auth::check() && Auth::user()->tenant_id) {
                $product->tenant_id = Auth::user()->tenant_id;
            }
        });
    }

    // === RELATIONSHIPS ===
    /** Photos, primary first. */
    public function videos()
    {
        return $this->hasMany(ProductVideo::class)->orderBy('id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(ProductForecast::class);
    }

    public function latestForecast(): HasOne
    {
        return $this->hasOne(ProductForecast::class)->latestOfMany('forecasted_at');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // === ACCESSORS & LOGIC ===

    public function getLowStockAttribute(): bool
    {
        $threshold = $this->tenant?->settings['low_stock_threshold'] ?? 10;
        return $this->quantity < $threshold;
    }

    /**
     * Updated to use the HasMany relationship instead of 
     * assuming variations is a JSON array/string attribute.
     */
    public function getVariationPrice($value)
    {
        $basePrice = $this->unit_price;

        // Use the relationship method to avoid property collision
        $variation = $this->variations->first(function ($v) use ($value) {
            return strtolower($v->name ?? '') === strtolower($value);
        });

        $price = $variation ? $variation->unit_price : $basePrice;

        return number_format($price, 2);
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->unit_price, 2);
    }

    public function getComputedQuantityAttribute(): float
    {
        // Use count() on relationship to check existence efficiently
        if ($this->variations()->count() > 0) {
            return (float) $this->variations()->sum('quantity');
        }

        return (float) $this->quantity;
    }

    public function resolvePrice(?int $variationId = null): float
    {
        if ($variationId) {
            $variation = $this->variations()->find($variationId);

            if ($variation && $variation->unit_price !== null) {
                return (float) $variation->unit_price;
            }
        }

        return (float) $this->unit_price;
    }

    public function syncTotalQuantity(): void
    {
        if ($this->variations()->exists()) {
            $total = (float) $this->variations()->sum('quantity');
            $this->update(['quantity' => $total]);
        }
    }

    /** Public shop pages show this model: refresh the shop site's cache when it changes. */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\Shop\ShopCache::bust());
        static::deleted(fn () => \App\Services\Shop\ShopCache::bust());
    }
}
