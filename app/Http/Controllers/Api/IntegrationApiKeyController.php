<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Str;

class IntegrationApiKeyController extends Controller
{
    public function index()
    {
        $tenantId = Auth::user()->tenant_id;

        $keys = IntegrationApiKey::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntegrationApiKey $key) => $this->maskSecret($key));

        return response()->json(['data' => $keys]);
    }

    public function store(Request $request)
    {
        $tenantId = Auth::user()->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scopes' => 'nullable|array',
        ]);

        $key = IntegrationApiKey::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'public_key' => 'int_' . Str::random(40),
            'secret' => Str::random(60),
            'scopes' => $data['scopes'] ?? [
                'products:read',
                'products:write',
                'orders:write',
            ],
        ]);

        return response()->json($key, 201);
    }

    public function update(Request $request, IntegrationApiKey $integrationApiKey)
    {
        $this->authorizeKey($integrationApiKey);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'scopes' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $integrationApiKey->update($data);

        return $this->maskSecret($integrationApiKey);
    }

    public function destroy(IntegrationApiKey $integrationApiKey)
    {
        $this->authorizeKey($integrationApiKey);
        $integrationApiKey->delete();

        return response()->json(null, 204);
    }

    protected function authorizeKey(IntegrationApiKey $key)
    {
        if ($key->tenant_id !== Auth::user()->tenant_id) {
            abort(403);
        }
    }

    /**
     * The live secret is shown in full exactly once — in store()'s
     * response, at the moment a tenant creates the key, the same way
     * Stripe/GitHub reveal a token only on creation. Every other response
     * masks it; re-sending a live secret on every list/update call would
     * defeat the point of it being a secret.
     */
    protected function maskSecret(IntegrationApiKey $key): array
    {
        $data = $key->toArray();
        $data['secret'] = $key->secret ? '••••••••' . substr($key->secret, -4) : null;

        return $data;
    }
}
