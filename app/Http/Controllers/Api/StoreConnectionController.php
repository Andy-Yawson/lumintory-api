<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Services\Ecommerce\StoreSyncFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StoreConnectionController extends Controller
{
    public function index()
    {
        return StoreConnection::where('tenant_id', Auth::user()->tenant_id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string', // e.g. shopify
            'store_name' => 'nullable|string',
            'domain' => 'nullable|string',
            'access_token' => 'nullable|string',
            'webhook_secret' => 'nullable|string',
            'commission_rate' => 'nullable|numeric',
            'settings' => 'nullable|array',
        ]);

        $data['tenant_id'] = Auth::user()->tenant_id;

        $connection = StoreConnection::create($data);

        // If provider supports automatic webhook registration
        if ($connection->provider === 'shopify') {
            RegisterShopWebhooksJob::dispatch($connection->id)
                ->onQueue('shop-hooks');
        }

        return response()->json($connection, 201);
    }

    public function destroy($id)
    {
        $connection = StoreConnection::findOrFail($id);
        $this->authorizeTenant($connection);

        // TODO: Optionally enqueue a job to remove webhooks from provider side
        $connection->delete();

        return response()->json(null, 204);
    }

    public function testConnection($id)
    {
        $connection = StoreConnection::findOrFail($id);
        $this->authorizeTenant($connection);

        // Here you'd ping whatever endpoint the provider exposes
        // For Shopify, e.g. GET /admin/api/2025-01/shop.json
        // For now, return a dummy OK:
        return response()->json([
            'ok' => true,
            'message' => 'Connection check not fully implemented yet.',
        ]);
    }

    public function logs($id)
    {
        $connection = StoreConnection::findOrFail($id);
        $this->authorizeTenant($connection);

        $events = $connection->webhookEvents()
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json($events);
    }

    public function syncProducts(StoreConnection $connection)
    {
        $this->authorizeTenant($connection); // ensure tenant authorization

        $service = StoreSyncFactory::make($connection);

        if (!$service) {
            return response()->json(['error' => 'No sync service'], 500);
        }

        $result = $service->syncProducts($connection, true);

        return response()->json(['success' => true, 'result' => $result]);
    }


    protected function authorizeTenant($model)
    {
        if ($model->tenant_id !== Auth::user()->tenant_id) {
            abort(403, 'Unauthorized tenant');
        }
    }
}
