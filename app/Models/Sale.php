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
        'customer_id',
        'payment_method',
        'variation_id'
    ];

    protected $casts = [
        'sale_date' => 'date',
        'variation_id' => 'integer',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);

        // Auto-calculate total & deduct stock on create
        static::creating(function ($sale) {
            $sale->total_amount = $sale->quantity * $sale->unit_price;

            $product = Product::lockForUpdate()->find($sale->product_id);

            if ($sale->variation_id) {
                $variation = ProductVariation::lockForUpdate()->find($sale->variation_id);

                if (!$variation || $variation->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient variation stock');
                }

                $variation->decrement('quantity', $sale->quantity);

                // Also decrement main product quantity
                if ($product && $product->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient product stock');
                }
                $product?->decrement('quantity', $sale->quantity);

            } else {
                if (!$product || $product->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient product stock');
                }

                $product->decrement('quantity', $sale->quantity);
            }
        });


        // Restore stock on delete
        static::deleting(function ($sale) {
            $product = Product::find($sale->product_id);

            if ($sale->variation_id) {
                $variation = ProductVariation::find($sale->variation_id);
                $variation?->increment('quantity', $sale->quantity);

                // Also restore main product quantity
                $product?->increment('quantity', $sale->quantity);
            } else {
                $product?->increment('quantity', $sale->quantity);
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
        return $this->variation?->unit_price ?? $this->unit_price;
    }



    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }
}
