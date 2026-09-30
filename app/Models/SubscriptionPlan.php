<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name', 'label', 'price_monthly', 'price_yearly',
        'currency', 'limits', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'float',
        'price_yearly'  => 'float',
        'limits'        => 'array',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
    ];

    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->where('is_active', true)->first();
    }

    public function getLimit(string $key, $default = null)
    {
        return $this->limits[$key] ?? $default;
    }
}
