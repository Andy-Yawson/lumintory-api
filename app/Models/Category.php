<?php

namespace App\Models;

use Auth;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\TenantScope;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'name'];

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope(new TenantScope);
        static::creating(function ($model) {
            if (Auth::check())
                $model->tenant_id = Auth::user()->tenant_id;
        });
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
