<?php

namespace App\Models;

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
        'sku',
        'quantity',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

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
}
