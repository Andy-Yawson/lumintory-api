<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopWebhookJob;
use App\Models\ShopWebhookEvent;
use App\Models\StoreConnection;
use App\Services\Ecommerce\StoreSyncFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $provider, $connectionId)
    {
        $connection = StoreConnection::find($connectionId);

        if (!$connection || $connection->provider !== $provider) {
            return response()->json(['ok' => false, 'message' => 'Invalid connection'], 404);
        }

        $body = (string) $request->getContent();
        $headers = $request->headers->all();

        $service = StoreSyncFactory::make($connection);
        if (!$service) {
            return response()->json(['ok' => false, 'message' => 'No sync service'], 500);
        }

        // verify webhook
        if (!$service->verifyWebhook($connection, $body, $headers)) {
            Log::warning('Webhook verification failed', ['connection_id' => $connection->id]);
            return response()->json(['ok' => false, 'message' => 'Verification failed'], 401);
        }

        $payload = $request->json()->all();

        // store raw event for auditing
        $event = ShopWebhookEvent::create([
            'store_connection_id' => $connection->id,
            'event_type' => $request->header('X-Event-Type') ?? $request->header('x-shopify-topic') ?? 'unknown',
            'payload' => $payload,
            'headers' => $headers
        ]);

        // Dispatch job to process the webhook
        ProcessShopWebhookJob::dispatch($event);

        return response()->json(['ok' => true]);
    }
}
