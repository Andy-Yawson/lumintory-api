<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'recipient',
        'message',
        'provider_message_id',
        'status',
        'segments',
        'cost',
        'provider_response'
    ];

    protected $casts = [
        'provider_response' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
