<?php

namespace App\Models\Dispatch;

use App\Models\ShopOrder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $table = 'dispatch_jobs';

    protected $guarded = ['id'];

    protected $hidden = ['handover_code_hash', 'handover_code_enc', 'track_token'];

    protected $casts = [
        'rates' => 'array', 'paid_at' => 'datetime', 'assigned_at' => 'datetime', 'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime', 'closed_at' => 'datetime', 'rated_at' => 'datetime', 'distance_km' => 'float',
        'goods_amount' => 'integer', 'delivery_fee' => 'integer', 'rider_share' => 'integer', 'goods_commission' => 'integer',
    ];

    public const OPEN = ['searching', 'assigned', 'picked_up'];

    public function order()
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id')->withoutGlobalScopes();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rider()
    {
        return $this->belongsTo(Rider::class, 'rider_id');
    }

    public function offers()
    {
        return $this->hasMany(Offer::class, 'job_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function total(): int
    {
        return $this->goods_amount + $this->delivery_fee;
    }

    /** What the shop owner's wallet gets. */
    public function ownerShare(): int
    {
        return $this->goods_amount - $this->goods_commission;
    }

    /** What Zinnvy keeps, before the payment provider's fee. */
    public function platformShare(): int
    {
        return $this->delivery_fee - $this->rider_share + $this->goods_commission;
    }

    /** The link the customer gets by SMS. */
    public function trackUrl(): ?string
    {
        return $this->track_token ? rtrim((string) config('services.shop.url', 'https://shop.zinnvy.com'), '/').'/track/'.$this->track_token : null;
    }

    public function ownerWalletRef(): string
    {
        return 'shop:'.$this->tenant_id;
    }
}
