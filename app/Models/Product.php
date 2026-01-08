<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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
        'category_id'
    ];

    protected $casts = [

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
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // === ACCESSORS ===
    public function getLowStockAttribute()
    {
        $threshold = $this->tenant?->settings['low_stock_threshold'] ?? 10;
        return $this->quantity < $threshold;
    }

    public function getVariationPrice($value)
    {
        $basePrice = $this->unit_price;

        if (!$this->variations || !is_array($this->variations)) {
            return number_format($basePrice, 2);
        }

        $variation = collect($this->variations)->first(function ($v) use ($value) {
            return strtolower($v['value'] ?? '') === strtolower($value);
        });

        $price = $variation['price'] ?? $basePrice;

        return number_format($price, 2);
    }

    public function forecasts()
    {
        return $this->hasMany(ProductForecast::class);
    }

    public function latestForecast()
    {
        return $this->hasOne(ProductForecast::class)->latestOfMany('forecasted_at');
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->unit_price, 2);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variations()
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function getComputedQuantityAttribute(): int
    {
        // If product has DB variations, sum them
        if ($this->relationLoaded('variations') || $this->variations()->exists()) {
            return (int) $this->variations()->sum('quantity');
        }

        // Otherwise, fall back to product quantity
        return (int) $this->quantity;
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

}
