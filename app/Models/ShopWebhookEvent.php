<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'store_connection_id',
        'event_type',
        'payload',
        'headers',
        'status',
        'attempts',
        'last_error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
    ];

    public function storeConnection()
    {
        return $this->belongsTo(StoreConnection::class);
    }
}
