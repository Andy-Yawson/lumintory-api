<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pay\PayClient;
use App\Services\Pay\PaySubscriptionActivator;
use App\Services\Pay\PayUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Receives signed events from Zinnvy Pay. Authenticated by signature only. */
class PayWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private PayClient $pay, private PaySubscriptionActivator $activator) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.pay.webhook_secret');
        $raw = $request->getContent();

        if (! $secret || ! $this->validSignature($raw, (string) $request->header('Zinnvy-Signature'), $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($raw, true) ?: [];
        $id = $event['data']['id'] ?? null;
        if (($event['type'] ?? null) !== 'payment.succeeded' || ! $id) {
            return response()->json(['received' => true]);
        }

        try {
            // Don't act on the event body alone: ask Pay what the payment really is.
            $payment = $this->pay->getPayment($id);
        } catch (PayUnavailable) {
            return response()->json(['message' => 'Try again.'], 503);   // Pay retries with backoff
        }

        return response()->json(['received' => true, 'result' => $this->activator->activate($payment)]);
    }

    private function validSignature(string $body, string $header, string $secret): bool
    {
        if (! preg_match('/t=(\d+),v1=([a-f0-9]{64})/', $header, $m)) {
            return false;
        }
        if (abs(time() - (int) $m[1]) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $m[1].'.'.$body, $secret), $m[2]);
    }
}
