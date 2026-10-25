<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    public const STATUSES = ['requested', 'confirmed', 'completed', 'cancelled', 'declined', 'no_show'];
    /** These hold their time slot; the rest free it up again. */
    public const HOLDS_SLOT = ['requested', 'confirmed'];

    protected $guarded = ['id'];

    protected $hidden = ['visitor_id'];

    protected $casts = [
        'price' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'source' => 'array',
        'confirmed_at' => 'datetime', 'reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}
