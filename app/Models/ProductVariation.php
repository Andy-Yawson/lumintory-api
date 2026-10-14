<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProductVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'name',
        'options',
        'sku',
        'quantity',
        'unit_price',
        'discount_price',
        'external_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'options' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope(new TenantScope);

        static::creating(function ($variation) {
            if (Auth::check()) {
                $variation->tenant_id = Auth::user()->tenant_id;
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Public shop pages show this model: refresh the shop site's cache when it changes. */
    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\Shop\ShopCache::bust());
        static::deleted(fn () => \App\Services\Shop\ShopCache::bust());
    }
}
