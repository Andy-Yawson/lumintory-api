<?php

namespace App\Helpers;

use App\Jobs\SendQueuedMail;
use Log;

class MailHelper
{
    /**
     * Queues an email. $message is plain text; it is laid out in the standard Zinnvy template (see App\Support\ZinnvyMail).
     *
     * @param  array{product?: string, from?: ?string, title?: ?string, preheader?: ?string, reason?: ?string}  $options
     *   `from` names the shop when the email is for its customer; `reason` is the "why you got this" line in the footer.
     */
    public static function sendEmailNotification($email, $subject, $message, array $options = []): void
    {
        self::queueEmail($email, $subject, $message, $options);
    }

    private static function queueEmail($email, $subject, $body, array $options)
    {
        try {
            dispatch(new SendQueuedMail($email, $subject, $body, $options))->delay(now()->addSeconds(30));
        } catch (\Exception $e) {
            Log::error("Email queue failed to dispatch for $email: " . $e->getMessage());
        }
    }
}
