<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    use HasFactory;

    protected $fillable = [
        'referrer_tenant_id',
        'referred_tenant_id',
        'tokens_awarded',
    ];

    public function referrer()
    {
        return $this->belongsTo(Tenant::class, 'referrer_tenant_id');
    }

    public function referredTenant()
    {
        return $this->belongsTo(Tenant::class, 'referred_tenant_id');
    }
}
