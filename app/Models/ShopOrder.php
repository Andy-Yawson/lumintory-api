<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'store_connection_id',
        'shop_order_id',
        'status',
        'payload',
        'imported_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'imported_at' => 'datetime',
    ];

    public function storeConnection()
    {
        return $this->belongsTo(StoreConnection::class);
    }
}
