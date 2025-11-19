<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'public_key',
        'secret',
        'scopes',
        'is_active',
    ];

    protected $casts = [
        'scopes' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
