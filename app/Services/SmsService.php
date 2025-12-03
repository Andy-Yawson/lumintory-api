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
        $this->baseUrl = env('SMS_BASE_URL');
        $this->appId = env('SMS_APP_ID');
        $this->appSecret = env('SMS_APP_SECRET');
    }

    public function queueSms($tenantId, $from, $to, $message)
    {
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

    public function calculateSegments($message)
    {
        $len = mb_strlen($message);
        return (int) ceil($len / 160);
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
