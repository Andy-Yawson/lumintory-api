<?php

namespace App\Console\Commands;

use App\Helpers\MailHelper;
use App\Services\SmsService;
use Illuminate\Console\Command;

class SMSCreditRemainder extends Command
{
    protected $signature = 'app:credit-remainder';
    protected $description = 'Check the balance credit remainder';

    public function handle()
    {
        $service = app(SmsService::class);
        $balance = $service->getProviderBalance();

        if ($balance['data']['balance'] < 15) {
            foreach (["yawsonandrews@gmail.com", "ugin.dev@gmail.com"] as $email) {
                MailHelper::sendEmailNotification(
                    $email,
                    "SMS BALANCE LOW",
                    "Provider balance is now: " . $balance['data']['balance']
                );
            }
        }
    }
}
