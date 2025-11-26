<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'from_plan',
        'to_plan',
        'event_type',
        'amount',
        'currency',
        'payment_reference',
        'gateway',
        'effective_at',
        'meta',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'meta' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
