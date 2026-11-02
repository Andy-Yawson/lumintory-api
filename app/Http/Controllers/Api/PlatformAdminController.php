<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopEvent;
use App\Models\ShopOrder;
use App\Models\SmsCredit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only account intelligence for the Zinnvy Identity admin console.
 * Aggregates only: no customer names, phone numbers or order contents leave here.
 * Models are queried by explicit tenant_id (there is no signed-in user to scope by).
 */
class PlatformAdminController extends Controller
{
    /** Ordered lifecycle: the last stage an account has reached. */
    private const STAGES = ['workspace', 'first_product', 'first_sale', 'shop_live', 'first_order'];

    /** GET /v1/platform/admin/accounts/{sub} */
    public function account(string $sub)
    {
        $user = User::where('zinnvy_sub', $sub)->first();
        $tenant = $user ? Tenant::find($user->tenant_id) : null;
        if (! $tenant) {
            return response()->json(['has_workspace' => false, 'stage' => 'signed_up']);
        }

        return response()->json($this->summary($tenant, $user));
    }

    /** POST /v1/platform/admin/lifecycle {subs: [...]} -> {sub: stage} for the users list. */
    public function lifecycle(Request $request)
    {
        $subs = $request->validate(['subs' => ['required', 'array', 'max:100'], 'subs.*' => ['string']])['subs'];
        $users = User::whereIn('zinnvy_sub', $subs)->get(['zinnvy_sub', 'tenant_id']);

        $out = array_fill_keys($subs, 'signed_up');
        foreach ($users as $user) {
            $out[$user->zinnvy_sub] = $this->milestones($user->tenant_id)['stage'];
        }

        return response()->json(['stages' => $out]);
    }

    /** GET /v1/platform/admin/overview: the marketplace and funnel, platform-wide. */
    public function overview()
    {
        $now = now();
        $d30 = $now->copy()->subDays(30);
        $d7 = $now->copy()->subDays(7);

        $productTenants = Product::query()->withoutGlobalScopes()->distinct()->pluck('tenant_id');
        $saleTenants = Sale::query()->withoutGlobalScopes()->distinct()->pluck('tenant_id');
        $liveShops = Shop::where('enabled', true)->whereNull('unlisted_at');
        $orderTenants = ShopOrder::query()->withoutGlobalScopes()->distinct()->pluck('tenant_id');

        $topShops = ShopOrder::query()->withoutGlobalScopes()
            ->where('shop_orders.created_at', '>=', $d30)->where('status', '!=', 'cancelled')
            ->join('shops', 'shops.id', '=', 'shop_orders.shop_id')
            ->join('tenants', 'tenants.id', '=', 'shop_orders.tenant_id')
            ->groupBy('shops.id', 'shops.slug', 'tenants.name')
            ->orderByDesc(DB::raw('count(*)'))->limit(8)
            ->get(['shops.slug', 'tenants.name', DB::raw('count(*) as orders'), DB::raw('sum(shop_orders.subtotal) as value')]);

        $sources = ShopEvent::where('type', 'view')->where('created_at', '>=', $d30)
            ->groupBy('source')->orderByDesc(DB::raw('count(distinct visitor_id)'))
            ->get(['source', DB::raw('count(distinct visitor_id) as visitors')]);

        $activeShopIds = ShopOrder::query()->withoutGlobalScopes()->where('created_at', '>=', $d30)->distinct()->pluck('shop_id');
        $listedCounts = Product::query()->withoutGlobalScopes()->where('shop_visible', true)->groupBy('tenant_id')->pluck(DB::raw('count(*)'), 'tenant_id');

        $needsAttention = Shop::where('enabled', true)->whereNull('unlisted_at')->with('tenant:id,name')->get()
            ->map(function (Shop $shop) use ($listedCounts, $activeShopIds) {
                $flags = [];
                if (($listedCounts[$shop->tenant_id] ?? 0) === 0) {
                    $flags[] = 'no_listed_products';
                }
                if (! $shop->whatsapp && ! $shop->notify_phone) {
                    $flags[] = 'no_contact';
                }
                if ($shop->created_at->lt(now()->subDays(30)) && ! $activeShopIds->contains($shop->id)) {
                    $flags[] = 'no_orders_30d';
                }

                return $flags ? ['slug' => $shop->slug, 'name' => $shop->tenant?->name, 'flags' => $flags] : null;
            })->filter()->values()->take(25);

        $recentShops = Shop::where('enabled', true)->with('tenant:id,name')->latest('updated_at')->limit(10)->get()
            ->map(fn (Shop $s) => [
                'slug' => $s->slug, 'name' => $s->tenant?->name, 'city' => $s->city,
                'in_marketplace' => $s->in_marketplace, 'unlisted' => (bool) $s->unlisted_at, 'updated_at' => $s->updated_at,
            ]);

        return response()->json([
            'generated_at' => $now,
            'workspaces' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('is_active', true)->count(),
                'new_7d' => Tenant::where('created_at', '>=', $d7)->count(),
                'new_30d' => Tenant::where('created_at', '>=', $d30)->count(),
                'active_7d' => Tenant::where('last_active_at', '>=', $d7)->count(),
                'by_plan' => Tenant::groupBy('plan')->pluck(DB::raw('count(*)'), 'plan'),
            ],
            'funnel' => [
                'workspaces' => Tenant::count(),
                'with_product' => Tenant::whereIn('id', $productTenants)->count(),
                'with_sale' => Tenant::whereIn('id', $saleTenants)->count(),
                'with_live_shop' => (clone $liveShops)->count(),
                'with_shop_order' => Tenant::whereIn('id', $orderTenants)->count(),
            ],
            'shops' => [
                'live' => (clone $liveShops)->count(),
                'in_marketplace' => (clone $liveShops)->where('in_marketplace', true)->count(),
                'unlisted' => Shop::whereNotNull('unlisted_at')->count(),
                'listed_products' => Product::query()->withoutGlobalScopes()->where('shop_visible', true)->count(),
                'orders_7d' => ShopOrder::query()->withoutGlobalScopes()->where('created_at', '>=', $d7)->count(),
                'orders_30d' => ShopOrder::query()->withoutGlobalScopes()->where('created_at', '>=', $d30)->count(),
                'order_value_30d' => (float) ShopOrder::query()->withoutGlobalScopes()->where('created_at', '>=', $d30)->where('status', '!=', 'cancelled')->sum('subtotal'),
                'visitors_30d' => (int) ShopEvent::where('type', 'view')->where('created_at', '>=', $d30)->distinct('visitor_id')->count('visitor_id'),
                'top_shops' => $topShops,
                'sources' => $sources,
                'recent' => $recentShops,
                'needs_attention' => $needsAttention,
            ],
        ]);
    }

    private function summary(Tenant $tenant, User $owner): array
    {
        $d30 = now()->subDays(30);
        $id = $tenant->id;
        $products = Product::query()->withoutGlobalScopes()->where('tenant_id', $id);
        $sales = Sale::query()->withoutGlobalScopes()->where('tenant_id', $id);
        $shop = Shop::where('tenant_id', $id)->first();
        $m = $this->milestones($id);

        $shopData = null;
        if ($shop) {
            $orders = ShopOrder::query()->withoutGlobalScopes()->where('shop_id', $shop->id);
            $views = ShopEvent::where('shop_id', $shop->id)->where('type', 'view')->where('created_at', '>=', $d30);
            $visitors = (int) (clone $views)->distinct('visitor_id')->count('visitor_id');
            $orders30 = (clone $orders)->where('created_at', '>=', $d30);
            $count30 = (clone $orders30)->count();
            $shopData = [
                'enabled' => $shop->enabled,
                'in_marketplace' => $shop->in_marketplace,
                'unlisted' => (bool) $shop->unlisted_at,
                'unlisted_reason' => $shop->unlisted_reason,
                'slug' => $shop->slug,
                'url' => $shop->slug ? rtrim(config('services.shop.url', 'https://shop.zinnvy.com'), '/').'/'.$shop->slug : null,
                'city' => $shop->city,
                'listed_products' => (clone $products)->where('shop_visible', true)->count(),
                'orders_total' => (clone $orders)->count(),
                'orders_30d' => $count30,
                'orders_new' => (clone $orders)->where('status', 'new')->count(),
                'order_value_30d' => (float) (clone $orders30)->where('status', '!=', 'cancelled')->sum('subtotal'),
                'last_order_at' => (clone $orders)->max('created_at'),
                'visitors_30d' => $visitors,
                'conversion_30d' => $visitors > 0 ? round($count30 / $visitors * 100, 1) : null,
                'sources_30d' => (clone $views)->groupBy('source')->orderByDesc(DB::raw('count(distinct visitor_id)'))->limit(5)
                    ->get(['source', DB::raw('count(distinct visitor_id) as visitors')]),
            ];
        }

        return [
            'has_workspace' => true,
            'stage' => $m['stage'],
            'milestones' => $m['at'],
            'workspace' => [
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'is_active' => (bool) $tenant->is_active,
                'subscription_ends_at' => $tenant->subscription_ends_at,
                'created_at' => $tenant->created_at,
                'last_active_at' => $tenant->last_active_at ?? null,
                'owner_role' => $owner->role,
                'staff_count' => User::where('tenant_id', $id)->count(),
                'sms_credits' => (int) SmsCredit::where('tenant_id', $id)->value('credits'),
            ],
            'inventory' => [
                'products' => (clone $products)->count(),
                'customers' => Customer::query()->withoutGlobalScopes()->where('tenant_id', $id)->count(),
                'sales_total' => (clone $sales)->count(),
                'sales_30d' => (clone $sales)->where('sale_date', '>=', $d30)->count(),
                'revenue_30d' => (float) (clone $sales)->where('sale_date', '>=', $d30)->sum('total_amount'),
                'last_sale_at' => (clone $sales)->max('sale_date'),
            ],
            'shop' => $shopData,
        ];
    }

    /** First-reached timestamps per lifecycle step and the highest stage reached. */
    private function milestones(int $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $shop = Shop::where('tenant_id', $tenantId)->first();
        $at = [
            'workspace' => $tenant?->created_at,
            'first_product' => Product::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->min('created_at'),
            'first_sale' => Sale::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->min('sale_date'),
            'shop_live' => $shop?->enabled ? $shop->created_at : null,
            'first_order' => ShopOrder::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->min('created_at'),
        ];

        $stage = 'signed_up';
        foreach (self::STAGES as $s) {
            if ($at[$s]) {
                $stage = $s;
            }
        }

        return ['stage' => $stage, 'at' => $at];
    }
}
