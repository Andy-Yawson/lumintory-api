<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['view', 'product_view', 'add_to_cart', 'checkout', 'share', 'chat', 'search'];
    public const SOURCES = ['whatsapp', 'instagram', 'facebook', 'tiktok', 'google', 'x', 'marketplace', 'direct', 'other'];

    protected $guarded = ['id'];
}
