<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Connect with Zinnvy" for the Zinnvy AI ↔ Inventory link (P4-C).
 *
 * Instead of an admin copying a base URL and a service token by hand, the
 * Zinnvy AI settings screen opens a short-lived popup at the Inventory web
 * app (/connect/ai). That popup — authenticated as a real Inventory user —
 * calls provision() below, which mints (or reuses) an `ai:read` credential
 * for the user's own tenant and hands back everything Zinnvy AI needs to
 * store the connection. The credential only ever grants read access to the
 * caller's own tenant, which Inventory continues to enforce (P4-A).
 */
class AiConnectController extends Controller
{
    public function provision(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        abort_unless($tenant && $tenant->is_active, 403, 'This workspace is not active.');

        // Reuse an existing Zinnvy AI credential for the tenant so repeated
        // authorizations don't pile up dead keys; otherwise mint a fresh one.
        $key = IntegrationApiKey::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('name', 'Zinnvy AI Co-Pilot')
            ->get()
            ->first(fn (IntegrationApiKey $k) => in_array('ai:read', $k->scopes ?? [], true));

        if (! $key) {
            $key = IntegrationApiKey::create([
                'tenant_id' => $tenant->id,
                'name' => 'Zinnvy AI Co-Pilot',
                'public_key' => 'int_' . Str::random(40),
                'secret' => Str::random(60),
                'scopes' => ['ai:read'],
            ]);
        }

        return response()->json([
            'token' => $key->public_key,
            'base_url' => rtrim(config('app.url'), '/') . '/api/v1/integrations/ai',
            'tenant_ref' => (string) $tenant->id,
            'tenant_name' => $tenant->name,
        ]);
    }
}
