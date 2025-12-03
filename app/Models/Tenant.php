<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'plan',
        'settings',
        'subscription_ends_at',
        'referral_code',
        'referred_by_tenant_id',
        'is_active'
    ];

    protected $casts = [
        'settings' => 'array', // Cast JSON to array for easy access
    ];

    // Relationship: A tenant has many products, sales, etc. (We'll add later)
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function returnItems()
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function referrer()
    {
        return $this->belongsTo(Tenant::class, 'referred_by_tenant_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_tenant_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->referral_code)) {
                $tenant->referral_code = self::generateUniqueReferralCode();
            }
        });
    }

    protected static function generateUniqueReferralCode()
    {
        do {
            $code = strtoupper(str()->random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function subscriptionHistories()
    {
        return $this->hasMany(SubscriptionHistory::class);
    }
}
