<?php

namespace App\Jobs;

use App\Services\Ecommerce\StoreSyncFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\ShopWebhookEvent;
use Illuminate\Support\Facades\Log;

class ProcessShopWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $event;

    public function __construct(ShopWebhookEvent $event)
    {
        $this->event = $event;
    }

    public function handle()
    {
        $connection = $this->event->storeConnection; // relationship on ShopWebhookEvent
        $service = StoreSyncFactory::make($connection);

        if (!$service) {
            Log::warning('No service for provider', ['provider' => $connection->provider]);
            return;
        }

        $payload = $this->event->payload;

        // Route by event type — each service handles its domain specifics
        $service->handleOrderWebhook($connection, $payload);
    }
}
