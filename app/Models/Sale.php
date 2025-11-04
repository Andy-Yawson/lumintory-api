<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Auth;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'color',
        'quantity',
        'unit_price',
        'total_amount',
        'notes',
        'sale_date',
    ];

    protected $casts = [
        'sale_date' => 'date',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);

        // Auto-calculate total & deduct stock on create
        static::creating(function ($sale) {
            $sale->total_amount = $sale->quantity * $sale->unit_price;

            // Deduct stock
            $product = Product::find($sale->product_id);
            if ($product && $sale->quantity <= $product->quantity) {
                $product->decrement('quantity', $sale->quantity);
            } else {
                throw new \Exception('Insufficient stock');
            }
        });

        // Restore stock on delete
        static::deleting(function ($sale) {
            $product = Product::find($sale->product_id);
            if ($product) {
                $product->increment('quantity', $sale->quantity);
            }
        });

        static::created(function ($sale) {
            if ($sale->customer_id) {
                $customer = Customer::find($sale->customer_id);
                $customer?->increment('total_spent', $sale->total_amount);
            }
        });

        static::deleted(function ($sale) {
            if ($sale->customer_id) {
                $customer = Customer::find($sale->customer_id);
                $customer?->decrement('total_spent', $sale->total_amount);
            }
        });
    }


    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // === ACCESSORS ===
    public function getVariationPrice()
    {
        return $this->product?->getVariationPrice($this->color) ?? $this->unit_price;
    }
}
