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
        'settings',
        'subscription_ends_at'
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
}
