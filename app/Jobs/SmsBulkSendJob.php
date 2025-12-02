<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class SmsBulkSendJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $tenantId;
    public $recipients;
    public $message;
    public $segments;

    public function __construct($tenantId, array $recipients, string $message, int $segments)
    {
        $this->tenantId = $tenantId;
        $this->recipients = $recipients;
        $this->message = $message;
        $this->segments = $segments;
    }

    public function handle()
    {
        foreach ($this->recipients as $phone) {
            SmsService::sendSingleSms($phone, $this->message, $this->segments);
        }
    }
}
