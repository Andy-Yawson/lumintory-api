<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'address',
        'total_spent',
        'total_returns',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function returns()
    {
        return $this->hasMany(ReturnItem::class);
    }

    // === ACCESSORS ===
    public function getHistoryAttribute()
    {
        return [
            'total_sales' => $this->sales()->count(),
            'total_returns' => $this->returns()->count(),
            'net_spent' => $this->total_spent - $this->returns()->sum('refund_amount'),
        ];
    }
}
