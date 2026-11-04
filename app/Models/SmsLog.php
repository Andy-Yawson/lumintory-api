<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'recipient',
        'message',
        'provider_message_id',
        'status',
        'segments',
        'cost',
        'provider_response'
    ];

    protected $casts = [
        'provider_response' => 'array',
    ];

    /**
     * What the provider's delivery report means for our four states. The column only holds queued/sent/delivered/failed,
     * so reports like "buffered" or "unknown" must be translated, never written as they arrive.
     */
    public static function statusFromProvider(?string $reported): string
    {
        return match (strtolower(trim((string) $reported))) {
            'delivered', 'delivrd', 'delivery', 'success' => 'delivered',
            'failed', 'undelivered', 'undeliv', 'rejected', 'rejectd', 'expired', 'expird', 'deleted', 'error' => 'failed',
            default => 'sent',   // buffered, accepted, enroute, queued, pending, unknown: it left us but is not confirmed yet
        };
    }

    /** Applies a delivery report without ever moving a message backwards (a late "buffered" must not undo "delivered"). */
    public function applyDeliveryReport(?string $reported, array $raw = []): void
    {
        $next = self::statusFromProvider($reported);
        $final = in_array($this->status, ['delivered', 'failed'], true);
        $this->provider_response = array_merge($this->provider_response ?? [], ['delivery_reports' => array_merge($this->provider_response['delivery_reports'] ?? [], [['status' => $reported, 'at' => now()->toIso8601String()]])]);
        if (! $final) {
            $this->status = $next;
        }
        $this->save();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
