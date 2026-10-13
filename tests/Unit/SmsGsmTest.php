<?php

namespace Tests\Unit;

use App\Services\SmsService;
use PHPUnit\Framework\TestCase;

class SmsGsmTest extends TestCase
{
    public function test_names_and_prices_survive_a_standard_sms(): void
    {
        $this->assertSame(
            "Thanks Ama! Earle's Closet received your order #1042 (GHS 250.00). They'll call - soon...",
            SmsService::toGsm("Thanks Ama! Earle’s Closet received your order #1042 (GH₵ 250.00). They’ll call — soon…"),
        );
    }

    public function test_accents_become_plain_letters_and_emoji_are_dropped(): void
    {
        $this->assertSame('Cote Akwaaba ', SmsService::toGsm('Côte Akwaaba 🎉')); // ô isn't GSM; é is
        $this->assertSame('Ñ é ü £ @ stay as they are', SmsService::toGsm('Ñ é ü £ @ stay as they are'), 'these are in the GSM set');
    }

    public function test_other_currencies_and_quotes(): void
    {
        $this->assertSame('NGN 500 and "best" price', SmsService::toGsm('₦500 and “best” price'));
    }

    public function test_every_output_character_is_gsm(): void
    {
        $out = SmsService::toGsm('Mix: ’“”–—…₵₦ éàô ɛɔ 😀 ☆ ¢');
        $this->assertDoesNotMatchRegularExpression('/[’“”–—…₵₦ɛɔ😀☆¢]/u', $out);
    }
}
