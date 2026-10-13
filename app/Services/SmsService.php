<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Jobs\SendSmsJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $baseUrl;
    protected string $appId;
    protected string $appSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.sms.base_url');
        $this->appId = config('services.sms.app_id');
        $this->appSecret = config('services.sms.app_secret');
    }

    public function queueSms($tenantId, $from, $to, $message)
    {
        $message = self::toGsm((string) $message);
        $segments = $this->calculateSegments($message);

        $log = SmsLog::create([
            'tenant_id' => $tenantId,
            'recipient' => $this->normalizePhone($to),
            'message' => $message,
            'segments' => $segments,
            'status' => 'queued',
        ]);

        dispatch((new SendSmsJob($log))->delay(now()->addSeconds(10)));

        return $log;
    }

    public function sendToProvider(SmsLog $log)
    {
        try {
            $payload = [
                "from" => 'Zinnvy',
                "to" => $this->normalizePhone($log->recipient),
                "type" => "1",
                "message" => $log->message,
                "callback_url" => route('sms.callback'),
                "app_id" => $this->appId,
                "app_secret" => $this->appSecret
            ];

            $response = Http::timeout(10)->post(
                "{$this->baseUrl}/messages/send",
                $payload
            );

            $data = $response->json();

            if ($response->failed() || ($data['status'] ?? '') !== "success") {
                $log->update([
                    'status' => 'failed',
                    'provider_response' => $data,
                ]);
                return;
            }

            $log->update([
                'status' => 'sent',
                'provider_message_id' => $data['data']['message_id'] ?? null,
                'cost' => $data['data']['cost'] ?? null,
                'provider_response' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error("SMS provider error", ['error' => $e->getMessage()]);
            $log->update(['status' => 'failed']);
        }
    }

    /** Same rule everywhere: one SMS up to 160 characters, then 153 per part. */
    public function calculateSegments($message)
    {
        return self::segments((string) $message);
    }

    public function getProviderBalance()
    {
        $response = Http::get("{$this->baseUrl}/account/balance", [
            'app_id' => $this->appId,
            'app_secret' => $this->appSecret
        ]);

        return $response->json();
    }

    public static function normalizePhone($number)
    {
        if (!$number)
            return null;

        $number = preg_replace('/\D/', '', $number);

        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            return "233" . substr($number, 1);
        }

        if (strlen($number) === 9) {
            return "233{$number}";
        }

        return $number;
    }

    /** GSM 03.38: what a standard SMS can carry. Anything else arrives as "?" on the phone. */
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXTENDED = '^{}\\[~]|€';

    /**
     * Make a message safe for a standard (GSM-7) SMS instead of letting the network turn
     * characters into "?": curly quotes become straight ones, dashes become "-", currency
     * symbols become codes (GH₵ 42 → GHS 42), accents are dropped (Akwaaba é → e).
     * Sending as Unicode SMS would keep them, but cuts each SMS from 160 to 70 characters.
     */
    public static function toGsm(string $text): string
    {
        $text = strtr($text, [
            'GH₵' => 'GHS', 'GH¢' => 'GHS', '₵' => 'GHS ', '¢' => 'GHS ', '₦' => 'NGN ', 'KSh' => 'KES',
            '’' => "'", '‘' => "'", '‚' => "'", '′' => "'", '`' => "'", '´' => "'",
            '“' => '"', '”' => '"', '„' => '"', '″' => '"', '«' => '"', '»' => '"',
            '–' => '-', '—' => '-', '‐' => '-', '‑' => '-', '−' => '-', '•' => '-', '·' => '-',
            '…' => '...', '×' => 'x', '÷' => '/', '™' => '', '®' => '', '©' => '(c)',
            "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ', "\t" => ' ',
        ]);

        $out = '';
        foreach (mb_str_split($text) as $ch) {
            if (mb_strpos(self::GSM_BASIC, $ch) !== false || mb_strpos(self::GSM_EXTENDED, $ch) !== false) {
                $out .= $ch;
                continue;
            }
            // Accented and other letters: closest plain version (ô → o, ɛ → e); drop what has none (emoji).
            // Split off accents (ô → o + ̂) and drop them; this behaves the same on every OS, unlike iconv.
            $plain = class_exists(\Normalizer::class) ? preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($ch, \Normalizer::FORM_D)) : $ch;
            if (preg_match('/[^\x20-\x7E]/', $plain) && function_exists('iconv')) {
                $plain = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain);
                $plain = preg_replace('/[\^`~"]/', '', $plain); // macOS iconv writes ô as ^o
            }
            $plain = preg_replace('/[^\x20-\x7E]/', '', (string) $plain);
            $out .= $plain === '?' ? '' : $plain;
        }

        return preg_replace('/ {2,}/', ' ', $out);
    }

    public static function segments(string $message): int
    {
        return mb_strlen($message) <= 160
            ? 1
            : (int) ceil(mb_strlen($message) / 153);
    }

    public static function sendSingleSms($phone, $message, $segments = null)
    {
        // Provider integration goes here
        Log::info("SMS SENT", [
            'phone' => $phone,
            'message' => $message,
            'segments' => $segments,
        ]);

        SmsLog::create([
            'phone' => $phone,
            'message' => $message,
            'segments' => $segments,
        ]);
    }
}
