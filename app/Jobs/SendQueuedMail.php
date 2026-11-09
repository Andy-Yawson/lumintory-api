<?php

namespace App\Jobs;

use App\Support\ZinnvyMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class SendQueuedMail implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $to;
    public $subject;
    public $body;
    public array $options;

    public function __construct($to, $subject, $body, array $options = [])
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;
        $this->options = $options;
    }

    /** Every email leaves in the standard Zinnvy template: HTML with the logo embedded, and a plain-text alternative. */
    public function handle()
    {
        Mail::send([], [], function ($message) {
            $logo = $message->embed(resource_path('email/zinnvy-mark.png'));
            $message->to($this->to)->subject($this->subject)
                ->html(ZinnvyMail::html($this->subject, (string) $this->body, $this->options, $logo))
                ->text(ZinnvyMail::text($this->subject, (string) $this->body, $this->options));
        });
    }
}
