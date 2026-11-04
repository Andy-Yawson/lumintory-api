<?php

namespace App\Models\Dispatch;

use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $table = 'dispatch_offers';

    protected $guarded = ['id'];

    protected $casts = ['expires_at' => 'datetime'];

    public function job()
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function rider()
    {
        return $this->belongsTo(Rider::class, 'rider_id');
    }
}
