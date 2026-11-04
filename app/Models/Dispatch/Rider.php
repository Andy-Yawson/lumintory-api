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

    /** @return array{avg: ?float, count: int} the customers' average (1-5) over rated deliveries */
    public function ratingSummary(): array
    {
        $row = Job::where('rider_id', $this->id)->whereNotNull('rating')->selectRaw('count(*) as n, avg(rating) as a')->first();

        return ['avg' => $row->n ? round((float) $row->a, 2) : null, 'count' => (int) $row->n];
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
