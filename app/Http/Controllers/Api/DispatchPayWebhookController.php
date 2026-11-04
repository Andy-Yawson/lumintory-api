<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispatch\Job;
use App\Services\Dispatch\DispatchService;
use App\Services\Pay\PayUnavailable;
use Illuminate\Http\Request;

/** Signed events from Zinnvy Pay about delivery payments. Authenticated by signature only. */
class DispatchPayWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, DispatchService $dispatch)
    {
        $secret = (string) config('services.pay_dispatch.webhook_secret');
        $raw = $request->getContent();
        if (! $secret || ! $this->validSignature($raw, (string) $request->header('Zinnvy-Signature'), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($raw, true) ?: [];
        $id = $event['data']['id'] ?? null;
        if (($event['type'] ?? null) !== 'payment.succeeded' || ! $id) {
            return response()->json(['received' => true]);
        }
        $job = Job::where('pay_intent_id', $id)->first();
        if ($job) {
            try {
                $dispatch->refreshPayment($job);   // never trust the body alone: ask Pay
            } catch (PayUnavailable) {
                return response()->json(['message' => 'Try again.'], 503);
            }
        }

        return response()->json(['received' => true]);
    }

    private function validSignature(string $body, string $header, string $secret): bool
    {
        if (! preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m) || abs(time() - (int) $m[1]) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
