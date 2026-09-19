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
        'customer_id',
        'refund_method'
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

        // Auto-add stock back on create. This is the ONLY place that
        // happens — callers (e.g. ReturnItemController::store()) must not
        // also increment stock themselves, or it gets restored twice.
        static::creating(function ($return) {
            self::adjustStock($return, 1);
        });

        // Deduct stock if return is deleted (rare, but safe) — mirrors
        // creating() above; not duplicated by callers for the same reason.
        static::deleting(function ($return) {
            self::adjustStock($return, -1);
        });

        static::created(function ($return) {
            if ($return->customer_id) {
                $customer = Customer::find($return->customer_id);
                $customer?->increment('total_returns');
            }
        });
    }

    /**
     * Moves stock the same way Sale's boot hooks took it away: when the
     * original sale was for a specific variation, both that variation's
     * quantity and the product's aggregate quantity move together — restore
     * one without the other and Product::computed_quantity (which sums
     * variations when a product has any) silently disagrees with what was
     * actually returned. $direction is +1 to give stock back, -1 to take it
     * away again.
     */
    private static function adjustStock(self $return, int $direction): void
    {
        $sale = Sale::find($return->sale_id);

        if ($sale?->variation_id) {
            ProductVariation::find($sale->variation_id)
                ?->increment('quantity', $direction * $return->quantity);
        }

        Product::find($return->product_id)
            ?->increment('quantity', $direction * $return->quantity);
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

    public function saleVariation()
    {
        return $this->hasOneThrough(
            ProductVariation::class,
            Sale::class,
            'id',
            'id',
            'sale_id',
            'variation_id'
        )->withoutGlobalScopes();
    }
}
