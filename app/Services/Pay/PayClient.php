<?php

namespace App\Services\Pay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Thin client for the Zinnvy Pay merchant API. Inventory never talks to a payment provider directly. */
class PayClient
{
    public static function configured(): bool
    {
        return (bool) (config('services.pay.url') && config('services.pay.secret_key') && config('services.pay.webhook_secret'));
    }

    /** @param array<string, mixed> $body */
    public function createPayment(array $body, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/v1/payment_intents', $body));
    }

    public function getPayment(string $id): array
    {
        return $this->send(fn (PendingRequest $h) => $h->get('/v1/payment_intents/'.rawurlencode($id)));
    }

    private function send(callable $call): array
    {
        try {
            $response = $call(Http::baseUrl(rtrim(config('services.pay.url'), '/'))->withToken(config('services.pay.secret_key'))->acceptJson()->timeout(15));
        } catch (ConnectionException $e) {
            throw new PayUnavailable('Zinnvy Pay could not be reached.', 0, $e);
        }

        if (! $response->successful()) {
            throw new PayUnavailable('Zinnvy Pay answered '.$response->status().': '.($response->json('error.message') ?? 'error'));
        }

        return $response->json();
    }
}
