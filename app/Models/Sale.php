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
        'variation_id',
        'discount'
    ];

    protected $casts = [
        'sale_date' => 'date',
        'variation_id' => 'integer',
        'quantity' => 'decimal:2',
    ];

    // === TENANT SCOPING ===
    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);

        // Auto-calculate total & deduct stock on create
        static::creating(function ($sale) {

            $sale->total_amount = ($sale->quantity * $sale->unit_price) - ($sale->discount ?? 0);
            // $sale->total_amount = $sale->quantity * $sale->unit_price;

            $product = Product::lockForUpdate()->find($sale->product_id);

            if ($sale->variation_id) {
                $variation = ProductVariation::lockForUpdate()->find($sale->variation_id);

                if (!$variation || $variation->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient variation stock');
                }

                // FIX: Manual subtraction instead of decrement() to support decimals
                $variation->quantity = $variation->quantity - $sale->quantity;
                $variation->save();

                // Also decrement main product quantity
                if ($product) {
                    if ($product->quantity < $sale->quantity) {
                        throw new \Exception('Insufficient product stock');
                    }
                    $product->quantity = $product->quantity - $sale->quantity;
                    $product->save();
                }

            } else {
                if (!$product || $product->quantity < $sale->quantity) {
                    throw new \Exception('Insufficient product stock');
                }

                $product->quantity = $product->quantity - $sale->quantity;
                $product->save();
            }
        });


        // Restore stock on delete
        static::deleting(function ($sale) {
            $product = Product::find($sale->product_id);

            if ($sale->variation_id) {
                $variation = ProductVariation::find($sale->variation_id);
                if ($variation) {
                    $variation->quantity = $variation->quantity + $sale->quantity;
                    $variation->save();
                }

                // Also restore main product quantity
                if ($product) {
                    $product->quantity = $product->quantity + $sale->quantity;
                    $product->save();
                }
            } else {
                if ($product) {
                    $product->quantity = $product->quantity + $sale->quantity;
                    $product->save();
                }
            }
        });

        static::created(function ($sale) {
            if ($sale->customer_id) {
                $customer = Customer::find($sale->customer_id);
                if ($customer) {
                    $customer->total_spent = $customer->total_spent + $sale->total_amount;
                    $customer->save();
                }
            }
        });

        static::deleted(function ($sale) {
            if ($sale->customer_id) {
                $customer = Customer::find($sale->customer_id);
                if ($customer) {
                    $customer->total_spent = $customer->total_spent - $sale->total_amount;
                    $customer->save();
                }
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
