<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoreConnection;
use App\Services\Integrations\ShopifyService;
use App\Services\Integrations\StoreSyncRunner;
use App\Services\Integrations\WooCommerceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Connect, list, sync and disconnect online stores. Authenticated routes act
 * for the signed-in tenant; the two callbacks are public because the store
 * (not our user's browser session) calls them, and are trusted only after the
 * provider's signature / our one-time state token checks out.
 */
class StoreConnectionController extends Controller
{
    public function __construct(
        private ShopifyService $shopify,
        private WooCommerceService $woo,
        private StoreSyncRunner $runner,
    ) {}

    public function index()
    {
        $connections = StoreConnection::where('tenant_id', Auth::user()->tenant_id)
            ->where('status', '!=', 'pending')
            ->orderByDesc('id')->get();

        return response()->json([
            'data' => $connections,
            'shopify_available' => $this->shopify->configured(),
        ]);
    }

    // ---------------------------------------------------------------- Shopify

    public function shopifyConnect(Request $request)
    {
        $request->validate(['shop' => 'required|string|max:255']);

        if (! $this->shopify->configured()) {
            return response()->json(['message' => 'Shopify connections are not enabled on this server yet.'], 503);
        }

        $shop = ShopifyService::normaliseShop($request->input('shop'));
        if (! $shop) {
            return response()->json(['message' => 'Enter your store address, like my-store.myshopify.com.'], 422);
        }

        $connection = $this->claim(StoreConnection::SHOPIFY, $shop);
        if (! $connection) {
            return response()->json(['message' => 'That store is already connected to another Zinnvy account.'], 409);
        }

        return response()->json(['url' => $this->shopify->authorizeUrl($shop, $connection->state_token)]);
    }

    /** Shopify sends the merchant's browser here after they approve the app. */
    public function shopifyCallback(Request $request)
    {
        $query = $request->query();

        if (! $this->shopify->validOAuthHmac($query)) {
            return $this->backToApp('shopify', 'invalid');
        }

        $shop = ShopifyService::normaliseShop((string) ($query['shop'] ?? ''));
        $connection = $shop && ! empty($query['state'])
            ? StoreConnection::where('provider', StoreConnection::SHOPIFY)->where('store_url', $shop)->where('state_token', $query['state'])->first()
            : null;

        if (! $connection || empty($query['code'])) {
            return $this->backToApp('shopify', 'expired');
        }

        $token = $this->shopify->exchangeToken($shop, $query['code']);
        if (! $token) {
            return $this->backToApp('shopify', 'denied');
        }

        $connection->update(['credentials' => ['access_token' => $token], 'status' => 'active', 'state_token' => null, 'last_error' => null]);
        $this->activate($connection);

        return $this->backToApp('shopify', 'connected');
    }

    // ------------------------------------------------------------ WooCommerce

    /** One-click: send the owner to WooCommerce's own approval screen. */
    public function wooConnect(Request $request)
    {
        $request->validate(['store_url' => 'required|string|max:255']);

        $url = WooCommerceService::normaliseUrl($request->input('store_url'));
        if (! $url) {
            return response()->json(['message' => 'Enter your store address, like my-store.com.'], 422);
        }

        $connection = $this->claim(StoreConnection::WOOCOMMERCE, $url);
        if (! $connection) {
            return response()->json(['message' => 'That store is already connected to another Zinnvy account.'], 409);
        }

        return response()->json(['url' => $this->woo->authorizeUrl($url, $connection->state_token, $this->appUrl('woocommerce', 'connected'))]);
    }

    /** WooCommerce POSTs the generated API keys here, server to server. */
    public function wooCallback(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|string',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        $connection = StoreConnection::where('provider', StoreConnection::WOOCOMMERCE)->where('state_token', $data['user_id'])->first();
        if (! $connection) {
            return response()->json(['message' => 'Unknown or expired connection.'], 404);
        }

        $connection->update([
            'credentials' => [
                'consumer_key' => $data['consumer_key'],
                'consumer_secret' => $data['consumer_secret'],
                'webhook_secret' => WooCommerceService::newWebhookSecret(),
            ],
            'state_token' => null,
            'status' => 'active',
            'last_error' => null,
        ]);
        $this->activate($connection);

        return response()->json(['ok' => true]);
    }

    /** Fallback for stores where the approval screen is blocked: paste the keys. */
    public function wooKeys(Request $request)
    {
        $data = $request->validate([
            'store_url' => 'required|string|max:255',
            'consumer_key' => 'required|string|max:255',
            'consumer_secret' => 'required|string|max:255',
        ]);

        $url = WooCommerceService::normaliseUrl($data['store_url']);
        if (! $url) {
            return response()->json(['message' => 'Enter your store address, like my-store.com.'], 422);
        }

        $connection = $this->claim(StoreConnection::WOOCOMMERCE, $url);
        if (! $connection) {
            return response()->json(['message' => 'That store is already connected to another Zinnvy account.'], 409);
        }

        $connection->credentials = [
            'consumer_key' => $data['consumer_key'],
            'consumer_secret' => $data['consumer_secret'],
            'webhook_secret' => WooCommerceService::newWebhookSecret(),
        ];

        if (! $this->woo->verify($connection)) {
            $connection->delete();

            return response()->json(['message' => "We couldn't sign in to that store with those keys. Check them and that the store is online."], 422);
        }

        $connection->fill(['state_token' => null, 'status' => 'active', 'last_error' => null])->save();
        $this->activate($connection);

        return response()->json($connection->fresh(), 201);
    }

    // ------------------------------------------------------------------ shared

    public function sync(StoreConnection $connection)
    {
        $this->own($connection);
        abort_unless($connection->isActive(), 409, 'This store is not connected.');

        $this->runner->syncProducts($connection);

        return response()->json($connection->fresh());
    }

    public function destroy(StoreConnection $connection)
    {
        $this->own($connection);
        $connection->delete();

        return response()->json(null, 204);
    }

    private function own(StoreConnection $c): void
    {
        abort_unless($c->tenant_id === Auth::user()->tenant_id, 403);
    }

    /**
     * Reserve a store for this tenant and mint the one-time state token that
     * ties the provider's callback back to them. A store live on another
     * account can't be taken over.
     */
    private function claim(string $provider, string $storeUrl): ?StoreConnection
    {
        $tenantId = Auth::user()->tenant_id;
        $existing = StoreConnection::where('provider', $provider)->where('store_url', $storeUrl)->first();

        if ($existing && $existing->tenant_id !== $tenantId && $existing->isActive()) {
            return null;
        }

        $connection = $existing ?? new StoreConnection(['provider' => $provider, 'store_url' => $storeUrl]);
        $connection->fill([
            'tenant_id' => $tenantId,
            'state_token' => Str::random(40),
            'status' => $existing && $existing->tenant_id === $tenantId && $existing->isActive() ? 'active' : 'pending',
        ])->save();

        return $connection;
    }

    /** Webhooks + first catalogue pull, once the response has gone out, so the redirect isn't held up. (A closure on the queue would trip the multitenancy job guard.) */
    private function activate(StoreConnection $connection): void
    {
        app()->terminating(function () use ($connection) {
            try {
                $connection->provider === StoreConnection::SHOPIFY
                    ? $this->shopify->registerWebhooks($connection)
                    : $this->woo->registerWebhooks($connection);
            } catch (\Throwable $e) {
                $connection->update(['last_error' => 'Connected, but live updates could not be set up: ' . substr($e->getMessage(), 0, 150)]);
            }
            $this->runner->syncProducts($connection);
        });
    }

    private function appUrl(string $provider, string $result): string
    {
        return rtrim((string) config('services.frontend_url'), '/') . "/dashboard/settings/integrations?{$provider}={$result}";
    }

    private function backToApp(string $provider, string $result)
    {
        return redirect()->away($this->appUrl($provider, $result));
    }
}
