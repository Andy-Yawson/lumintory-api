<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'shop_order_id',
        'sale_id',
        'amount',
        'percentage',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }
}
