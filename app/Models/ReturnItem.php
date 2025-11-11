<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'product_id',
        'color',
        'quantity',
        'refund_amount',
        'reason',
        'return_date',
        'variation',
        'customer_id'
    ];

    protected $casts = [
        'return_date' => 'date',
        'variation' => 'array',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);

        // Auto-add stock back on create
        static::creating(function ($return) {
            $product = Product::find($return->product_id);
            if ($product) {
                $product->increment('quantity', $return->quantity);
            }
        });

        // Deduct stock if return is deleted (rare, but safe)
        static::deleting(function ($return) {
            $product = Product::find($return->product_id);
            if ($product) {
                $product->decrement('quantity', $return->quantity);
            }
        });

        static::created(function ($return) {
            if ($return->customer_id) {
                $customer = Customer::find($return->customer_id);
                $customer?->increment('total_returns');
            }
        });
    }

    // === RELATIONSHIPS ===
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
