<?php

namespace App\Helpers;

use App\Jobs\SendQueuedMail;
use Log;

class MailHelper
{

    public static function sendEmailNotification($email, $subject, $message): void
    {
        self::queueEmail($email, $subject, $message);
    }

    private static function queueEmail($email, $subject, $body)
    {
        try {
            dispatch(new SendQueuedMail($email, $subject, $body))->delay(now()->addSeconds(30));
        } catch (\Exception $e) {
            Log::error("Email queue failed to dispatch for $email: " . $e->getMessage());
        }
    }
}
