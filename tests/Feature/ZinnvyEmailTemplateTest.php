<?php

namespace Tests\Feature;

use App\Helpers\MailHelper;
use App\Jobs\SendQueuedMail;
use App\Support\ZinnvyMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ZinnvyEmailTemplateTest extends TestCase
{
    private function html(string $body, array $o = []): string
    {
        return ZinnvyMail::html('Subject line', $body, $o + ['product' => 'Inventory'], 'cid:logo');
    }

    public function test_plain_bodies_are_laid_out_in_the_standard_template(): void
    {
        $html = $this->html("Hi Ama,\n\nHere is your order:\n\n- 2 × Wig — GH₵ 400.00\n- 1 × Comb — GH₵ 20.00\n\nCustomer: Ama\nPhone: 0244000111\n\nOpen the order: https://app.zinnvy.com/orders/1\n\nRegards,\nZinnvy.");
        $this->assertStringContainsString('src="cid:logo"', $html);
        $this->assertStringContainsString('Sent by', $html);              // standard footer
        $this->assertStringContainsString('<li', $html);                  // list
        $this->assertStringContainsString('Phone</td>', $html);           // details card
        $this->assertStringContainsString('>Open the order</a>', $html);  // link line became a button
        $this->assertStringNotContainsString('Regards', $html);           // hand-written sign-off is gone: the footer has it
    }

    public function test_content_is_escaped_and_links_are_safe(): void
    {
        $html = $this->html("<script>alert(1)</script> hello https://example.com/a?b=1&c=2.\n\n[[button: Go | https://example.com/x]]");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('href="https://example.com/x"', $html);
    }

    public function test_customer_emails_name_the_shop_and_say_why_they_got_it(): void
    {
        $html = $this->html('Thanks for ordering.', ['from' => "Akosua's Boutique", 'title' => 'We got your order #1001', 'reason' => 'You placed an order with Akosua.']);
        $this->assertStringContainsString('Akosua&#039;s Boutique', $html);
        $this->assertStringContainsString('We got your order #1001', $html);
        $this->assertStringContainsString('You placed an order with Akosua.', $html);
    }

    public function test_every_queued_email_goes_out_as_html_with_a_text_alternative_and_the_logo_attached(): void
    {
        config(['mail.default' => 'array']);
        (new SendQueuedMail('owner@example.test', 'New order #1', "New order.\n\n[[button: Open the order | https://app.zinnvy.com/o/1]]", ['product' => 'Inventory']))->handle();
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringContainsString('Open the order', (string) $sent->getHtmlBody());
        $this->assertStringContainsString('Open the order: https://app.zinnvy.com/o/1', (string) $sent->getTextBody());
        $this->assertCount(1, $sent->getAttachments(), 'the logo is embedded');
        $this->assertStringContainsString('cid:', (string) $sent->getHtmlBody());
    }

    public function test_the_text_alternative_is_clean(): void
    {
        $text = ZinnvyMail::text('S', "Hello.\n\n[[button: Open it | https://x.test/a]]\n\n[[note: Careful.]]\n\nRegards,\nZinnvy.", ['product' => 'Inventory']);
        $this->assertStringContainsString('Open it: https://x.test/a', $text);
        $this->assertStringContainsString('Careful.', $text);
        $this->assertStringNotContainsString('[[', $text);
        $this->assertStringContainsString('support@zinnvy.com', $text);
    }
}
