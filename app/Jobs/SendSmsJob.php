<?php

namespace App\Jobs;

use App\Models\SmsLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use App\Services\SmsService;

class SendSmsJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;            // retry SMS send 3 times
    public int $backoff = 15;         // retry every 15 seconds

    protected SmsLog $log;

    public function __construct(SmsLog $log)
    {
        $this->log = $log;
    }

    public function handle(SmsService $smsService)
    {
        $smsService->sendToProvider($this->log);
    }
}
