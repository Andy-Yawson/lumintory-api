<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Helpers\MailHelper;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SMSCreditRemainder extends Command
{
    protected $signature = 'app:credit-remainder';
    protected $description = 'Check the balance credit remainder';

    public function handle()
    {
        try {
            $balance = app(SmsService::class)->getProviderBalance()['data']['balance'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('SMS provider balance check failed: '.$e->getMessage());

            return;
        }

        // Internal alert for the Zinnvy team: at most once a day while the balance stays low
        // (it used to email every hour).
        if ($balance === null || $balance >= 15 || ! Cache::add('sms-provider-balance-alert', true, now()->addDay())) {
            return;
        }

        foreach (['yawsonandrews@gmail.com', 'ugin.dev@gmail.com'] as $email) {
            MailHelper::sendEmailNotification($email, 'SMS provider balance is low', "The SMS provider balance is now {$balance}. Top it up so customer messages keep sending.");
        }
    }
}
