<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class StoreConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider',
        'store_name',
        'domain',
        'access_token',
        'webhook_secret',
        'settings',
        'enabled',
        'commission_rate',
    ];

    protected $casts = [
        'settings' => 'array',
        'enabled' => 'boolean',
    ];

    // Access token encryption
    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = $value
            ? Crypt::encryptString($value)
            : null;
    }

    public function getAccessTokenAttribute($value)
    {
        return $value
            ? Crypt::decryptString($value)
            : null;
    }

    // Webhook secret encryption
    public function setWebhookSecretAttribute($value)
    {
        $this->attributes['webhook_secret'] = $value
            ? Crypt::encryptString($value)
            : null;
    }

    public function getWebhookSecretAttribute($value)
    {
        return $value
            ? Crypt::decryptString($value)
            : null;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shopProducts()
    {
        return $this->hasMany(ShopProduct::class);
    }

    public function webhookEvents()
    {
        return $this->hasMany(ShopWebhookEvent::class);
    }

    public function shopOrders()
    {
        return $this->hasMany(ShopOrder::class);
    }
}
