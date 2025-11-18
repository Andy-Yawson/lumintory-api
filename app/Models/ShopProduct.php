<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'store_connection_id',
        'shop_product_id',
        'shop_variant_id',
        'product_id',
        'sku',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function storeConnection()
    {
        return $this->belongsTo(StoreConnection::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
