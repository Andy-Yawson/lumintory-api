<?php

namespace App\Models\Dispatch;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Rider extends Model
{
    protected $table = 'dispatch_riders';

    protected $guarded = ['id'];

    protected $hidden = ['id_photo_path', 'selfie_path', 'id_number'];

    protected $casts = ['online' => 'boolean', 'last_seen_at' => 'datetime', 'last_lat' => 'float', 'last_lng' => 'float'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function jobs()
    {
        return $this->hasMany(Job::class, 'rider_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The wallet reference Zinnvy Pay knows this rider by. */
    public function walletRef(): string
    {
        return 'rider:'.$this->id;
    }
}
