<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class ShopOrder extends Model
{
    public const STATUSES = ['new', 'contacted', 'confirmed', 'completed', 'cancelled'];

    protected $guarded = ['id'];

    protected $hidden = ['ip_hash', 'visitor_id'];

    protected $casts = [
        'sale_ids' => 'array',
        'source' => 'array',
        'subtotal' => 'decimal:2',
        'contacted_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function items()
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
