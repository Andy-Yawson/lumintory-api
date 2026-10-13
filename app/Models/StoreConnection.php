<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoreConnection extends Model
{
    public const SHOPIFY = 'shopify';
    public const WOOCOMMERCE = 'woocommerce';

    protected $fillable = [
        'tenant_id', 'provider', 'store_url', 'status', 'state_token',
        'credentials', 'product_count', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['credentials', 'state_token'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->uuid ??= (string) Str::uuid();
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }
}
