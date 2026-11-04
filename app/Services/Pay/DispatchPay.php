<?php

namespace App\Services\Pay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Zinnvy Pay as Zinnvy Dispatch's merchant: delivery payments, and the wallets they are split into. */
class DispatchPay
{
    public static function configured(): bool
    {
        return (bool) (config('services.pay_dispatch.url') && config('services.pay_dispatch.secret_key'));
    }

    public function createPayment(array $body, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/v1/payment_intents', $body));
    }

    public function getPayment(string $id): array
    {
        return $this->send(fn (PendingRequest $h) => $h->get('/v1/payment_intents/'.rawurlencode($id)));
    }

    public function ensureWallet(string $ownerRef, string $kind, ?string $name): array
    {
        return $this->send(fn (PendingRequest $h) => $h->post('/v1/wallets', ['owner_ref' => $ownerRef, 'kind' => $kind, 'name' => $name]));
    }

    public function wallet(string $ownerRef): array
    {
        return $this->send(fn (PendingRequest $h) => $h->get('/v1/wallets/'.rawurlencode($ownerRef)));
    }

    public function entries(string $ownerRef, ?string $cursor = null): array
    {
        return $this->send(fn (PendingRequest $h) => $h->get('/v1/wallets/'.rawurlencode($ownerRef).'/entries', array_filter(['cursor' => $cursor])));
    }

    public function setPayout(string $ownerRef, string $network, string $number): array
    {
        return $this->send(fn (PendingRequest $h) => $h->put('/v1/wallets/'.rawurlencode($ownerRef).'/payout', ['network' => $network, 'number' => $number]));
    }

    public function withdraw(string $ownerRef, int $amount, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('/v1/wallets/'.rawurlencode($ownerRef).'/withdrawals', ['amount' => $amount]));
    }

    public function withdrawals(string $ownerRef): array
    {
        return $this->send(fn (PendingRequest $h) => $h->get('/v1/wallets/'.rawurlencode($ownerRef).'/withdrawals'));
    }

    /** Money from Zinnvy itself into a wallet (e.g. a failed-trip fee). Idempotent on the key. */
    public function adjust(string $ownerRef, int $amount, string $label, string $key): array
    {
        return $this->send(fn (PendingRequest $h) => $h->post('/v1/wallets/'.rawurlencode($ownerRef).'/adjustments', ['amount' => $amount, 'label' => $label, 'key' => $key]));
    }

    private function send(callable $call): array
    {
        try {
            $response = $call(Http::baseUrl(rtrim(config('services.pay_dispatch.url'), '/'))->withToken(config('services.pay_dispatch.secret_key'))->acceptJson()->timeout(15));
        } catch (ConnectionException $e) {
            throw new PayUnavailable('Zinnvy Pay could not be reached.', 0, $e);
        }
        if ($response->status() === 422) {
            throw new PayRejected((string) ($response->json('error.message') ?? 'Zinnvy Pay refused that.'));
        }
        if (! $response->successful()) {
            throw new PayUnavailable('Zinnvy Pay answered '.$response->status().': '.($response->json('error.message') ?? 'error'));
        }

        return $response->json();
    }
}
