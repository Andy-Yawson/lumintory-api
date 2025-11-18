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
        'variations',
        'description',
    ];

    protected $casts = [
        'variations' => 'array', // Auto-convert JSON to array
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
        if (!$this->variations || !is_array($this->variations)) {
            return $this->unit_price;
        }

        $variation = collect($this->variations)->first(function ($v) use ($value) {
            return strtolower($v['value'] ?? '') === strtolower($value);
        });

        return $variation['price'] ?? $this->unit_price;
    }

    public function forecasts()
    {
        return $this->hasMany(ProductForecast::class);
    }

    public function latestForecast()
    {
        return $this->hasOne(ProductForecast::class)->latestOfMany('forecasted_at');
    }
}
