<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IntegrationApiKey;
use App\Models\Product;
use App\Services\CopilotContextToken;
use Illuminate\Http\Request;

/**
 * Two things the Zinnvy AI Co-Pilot needs from Inventory, both scoped to one tenant:
 *
 *  - issue(): a signed page context for the signed-in Inventory user (who they are,
 *    which shop, which page, which product or customer is open). The entity's name is
 *    looked up here, inside the user's own tenant; the browser's value is never used.
 *  - member(): server-to-server, "is this Zinnvy account an active user of this shop?"
 *    so Zinnvy AI only answers about a shop for people who belong to it.
 */
class CopilotContextController extends Controller
{
    private const PAGES = ['dashboard', 'products', 'product', 'sales', 'returns', 'customers', 'customer', 'reports', 'forecasts', 'other'];

    public function issue(Request $request)
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'in:'.implode(',', self::PAGES)],
            'entity_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($tenant && $tenant->is_active, 403, 'This workspace is not active.');

        $key = IntegrationApiKey::where('tenant_id', $tenant->id)->where('is_active', true)->where('name', 'Zinnvy AI Co-Pilot')->get()
            ->first(fn (IntegrationApiKey $k) => in_array('ai:read', $k->scopes ?? [], true));
        if (! $key) {
            return response()->json(['message' => 'Zinnvy AI is not connected for this workspace.'], 409);
        }

        $entity = null;
        if (! empty($data['entity_id']) && in_array($data['page'], ['product', 'customer'], true)) {
            // Looked up inside this user's tenant, so a forged id from another shop finds nothing.
            $row = $data['page'] === 'product'
                ? Product::where('tenant_id', $tenant->id)->find($data['entity_id'])
                : Customer::where('tenant_id', $tenant->id)->find($data['entity_id']);
            if ($row) {
                $entity = ['type' => $data['page'], 'id' => (int) $row->id, 'name' => (string) $row->name];
            }
        }

        $claims = [
            'tenant' => (string) $tenant->id,
            'tenant_name' => $tenant->name,
            'sub' => $user->zinnvy_sub,
            'uid' => (int) $user->id,
            'role' => $user->role,
            'app_url' => rtrim((string) config('copilot.frontend_url'), '/'),
            'page' => ['key' => $data['page'], 'entity' => $entity],
        ];

        return response()->json([
            'token' => CopilotContextToken::sign($claims, $key),
            'expires_in' => 900,
            'page' => $claims['page'],
        ]);
    }

    /** GET /v1/integrations/ai/members/{sub} (ai:read): is this Zinnvy account an active user of the key's shop? */
    public function member(Request $request, string $sub)
    {
        $tenant = $request->attributes->get('integration_tenant');
        $user = $tenant->users()->where('zinnvy_sub', $sub)->first();
        if (! $user) {
            return response()->json(['member' => false], 404);
        }

        return response()->json(['member' => true, 'role' => $user->role, 'name' => $user->name]);
    }
}
