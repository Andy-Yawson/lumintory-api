<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductForecast extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'window_days',
        'avg_daily_sales',
        'predicted_days_to_stockout',
        'current_quantity',
        'stock_risk_level',
        'forecasted_at',
        'reorder_point',
        'safety_stock',
    ];

    protected $casts = [
        'avg_daily_sales' => 'float',
        'predicted_days_to_stockout' => 'float',
        'current_quantity' => 'integer',
        'forecasted_at' => 'datetime',
        'reorder_point' => 'integer',
        'safety_stock' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
