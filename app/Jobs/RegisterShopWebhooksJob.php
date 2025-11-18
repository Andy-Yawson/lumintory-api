<?php

namespace App\Jobs;

use App\Models\StoreConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterShopWebhooksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $connectionId;

    public function __construct(int $connectionId)
    {
        $this->connectionId = $connectionId;
    }

    public function handle(): void
    {
        $connection = StoreConnection::find($this->connectionId);

        if (!$connection || $connection->provider !== 'shopify') {
            return;
        }

        $domain = $connection->domain; // e.g. my-shop.myshopify.com
        $token = $connection->access_token;

        if (!$domain || !$token) {
            Log::warning('RegisterShopWebhooksJob missing domain or token', [
                'connection_id' => $this->connectionId,
            ]);
            return;
        }

        $webhookUrl = config('app.url') . "/api/webhooks/shopify/{$connection->id}";

        $topics = [
            'orders/create',
            'orders/updated',
            'orders/cancelled',
        ];

        $endpointBase = "https://{$domain}/admin/api/2025-01";

        foreach ($topics as $topic) {
            try {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ])->post("{$endpointBase}/webhooks.json", [
                            'webhook' => [
                                'topic' => $topic,
                                'address' => $webhookUrl,
                                'format' => 'json',
                            ],
                        ]);

                if (!$response->successful()) {
                    Log::error('Failed to register Shopify webhook', [
                        'connection_id' => $this->connectionId,
                        'topic' => $topic,
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Exception registering Shopify webhooks: ' . $e->getMessage(), [
                    'connection_id' => $this->connectionId,
                    'topic' => $topic,
                ]);
            }
        }
    }
}
