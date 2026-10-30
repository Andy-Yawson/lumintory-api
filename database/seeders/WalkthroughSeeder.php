<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopEvent;
use App\Models\ShopOrder;
use App\Models\ShopService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Akosua's Boutique": a clean, believable demo shop used to capture the video walkthrough.
 * STAGING ONLY: refuses to run in production or without SEED_DEMO_DATA=true.
 *
 *   WALKTHROUGH_STAGE=empty php artisan db:seed --class='Database\Seeders\WalkthroughSeeder'   # brand-new workspace
 *   WALKTHROUGH_STAGE=full  ...                                                                # same workspace, fully lived in
 *   WALKTHROUGH_STAGE=reset ...                                                                # remove it
 *
 * Nothing here sends SMS or email: orders, bookings and visits are written straight to their tables.
 * Phone numbers are obviously fake (0200000xxx).
 */
class WalkthroughSeeder extends Seeder
{
    public const DOMAIN = 'akosua.demo.zinnvy.test';
    public const EMAIL = 'akosua@demo.zinnvy.test';

    private ?Tenant $tenant = null;

    public function run(): void
    {
        if (app()->environment('production') || getenv('SEED_DEMO_DATA') !== 'true') {
            $this->command?->warn('WalkthroughSeeder only runs on staging with SEED_DEMO_DATA=true.');

            return;
        }
        mt_srand(2026);
        $stage = getenv('WALKTHROUGH_STAGE') ?: 'full';
        $this->tenant = Tenant::where('domain', self::DOMAIN)->first();

        match ($stage) {
            'reset' => $this->reset(),
            'empty' => $this->empty(),
            default => $this->full(),
        };
    }

    // ---------------------------------------------------------------- stages

    private function empty(): void
    {
        $this->reset();
        $this->tenant = Tenant::create([
            'name' => "Akosua's Boutique", 'domain' => self::DOMAIN, 'plan' => 'pro', 'is_active' => true,
            'subscription_ends_at' => now()->addYear(),
            'settings' => ['currency' => 'GHS', 'currency_symbol' => 'GH₵'],
        ]);
        $this->user('Akosua Mensah', self::EMAIL, 'Administrator');
        $this->command?->info("Empty workspace ready: tenant {$this->tenant->id}, {$this->tenant->name}.");
    }

    private function full(): void
    {
        if (! $this->tenant) {
            $this->empty();
        }
        $t = $this->tenant;
        $admin = User::where('email', self::EMAIL)->firstOrFail();
        Auth::setUser($admin); // so the app's own scopes and rules apply, exactly as in real use

        $this->wipeData();

        $cat = collect(['Wigs & Hair', 'Clothing', 'Footwear', 'Accessories', 'Skincare'])->mapWithKeys(fn ($n) => [$n => Category::create(['tenant_id' => $t->id, 'name' => $n])->id]);

        // name, category, price, stock, min stock, options (name => values) or null
        $catalog = [
            ['Brazilian Body Wave Wig', 'Wigs & Hair', 950, 0, 2, ['Colour' => ['Natural Black', 'Dark Brown'], 'Length' => ['16"', '20"', '24"']], ['Length' => ['16"' => 0, '20"' => 250, '24"' => 480]], [3, 6]],
            ['HD Lace Frontal Wig 18"', 'Wigs & Hair', 1200, 7, 3, null, null, null],
            ['Xpression Braiding Hair', 'Wigs & Hair', 35, 140, 30, null, null, null],
            ['Edge Control & Wig Cap Set', 'Wigs & Hair', 45, 22, 10, null, null, null],
            ['Ankara Wrap Dress', 'Clothing', 260, 0, 5, ['Size' => ['S', 'M', 'L', 'XL']], null, [4, 9]],
            ['Kente Print Shirt', 'Clothing', 185, 0, 5, ['Size' => ['M', 'L', 'XL']], null, [3, 8]],
            ['Leather Sandals', 'Footwear', 150, 0, 4, ['Colour' => ['Brown', 'Black'], 'Size' => ['40', '41', '42', '43']], null, [1, 6]],
            ['Beaded Bracelet', 'Accessories', 35, 48, 12, null, null, null],
            ['Cowrie Shell Necklace', 'Accessories', 55, 9, 10, null, null, null],
            ['Raw Shea Butter 250ml', 'Skincare', 28, 3, 10, null, null, null],
            ['Hair Growth Oil 100ml', 'Skincare', 55, 31, 10, null, null, null],
            ['Lip Gloss Set (3)', 'Skincare', 40, 64, 15, null, null, null],
        ];

        $products = [];
        foreach ($catalog as [$name, $c, $price, $stock, $min, $opts, $adds, $range]) {
            $p = new Product(['name' => $name, 'unit_price' => $price, 'quantity' => $stock, 'min_stock_threshold' => $min, 'lead_time_days' => 7,
                'category_id' => $cat[$c], 'description' => null, 'option_names' => $opts ? array_keys($opts) : null]);
            $p->tenant_id = $t->id;
            $p->shop_visible = true;
            $p->save();
            $variations = [];
            if ($opts) {
                $combos = array_reduce(array_values($opts), fn ($acc, $vals) => array_merge(...array_map(fn ($a) => array_map(fn ($v) => [...$a, $v], $vals), $acc ?: [[]])), []);
                foreach ($combos as $combo) {
                    $o = array_combine(array_keys($opts), $combo);
                    $extra = collect($adds ?? [])->reduce(fn ($s, $m, $k) => $s + ($m[$o[$k] ?? ''] ?? 0), 0);
                    $v = ProductVariation::create(['tenant_id' => $t->id, 'product_id' => $p->id, 'name' => implode(' / ', $combo), 'options' => $o,
                        'quantity' => mt_rand($range[0] + 25, $range[1] + 40), 'unit_price' => $price + (is_array($adds) ? $extra : 0)]);
                    $variations[] = $v;
                }
                // A couple running low or out, so alerts and "sold out" states show up.
                $variations[1]->update(['quantity' => 2]);
                $variations[count($variations) - 1]->update(['quantity' => 0]);
                $p->update(['quantity' => collect($variations)->sum(fn ($v) => (float) $v->fresh()->quantity)]);
            }
            $products[$name] = [$p, $variations];
        }

        $names = ['Ama Mensah', 'Kofi Boateng', 'Efua Asante', 'Yaw Darko', 'Abena Owusu', 'Kwesi Appiah', 'Adwoa Frimpong', 'Nana Yeboah', 'Akua Sarpong', 'Esi Quaye', 'Kojo Antwi', 'Maame Serwaa'];
        $customers = [];
        foreach ($names as $i => $n) {
            $customers[] = Customer::create(['tenant_id' => $t->id, 'name' => $n, 'phone' => '02000000'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]);
        }

        // ~21 days of sales, busier recently and at weekends; stock comes off by the app's own rules.
        $methods = ['Mobile Money', 'Mobile Money', 'Mobile Money', 'Cash', 'Cash', 'Card'];
        $weights = collect($products)->keys()->map(fn ($n, $i) => max(1, 9 - $i))->all();
        $sales = 0;
        for ($d = 20; $d >= 0; $d--) {
            $day = Carbon::today()->subDays($d);
            $count = (int) round(1.5 + (20 - $d) * 0.12 + ($day->isWeekend() ? 2 : 0) + mt_rand(0, 2));
            for ($i = 0; $i < $count; $i++) {
                [$p, $vars] = $products[array_keys($products)[$this->pick($weights)]];
                $v = $vars ? $vars[mt_rand(0, count($vars) - 1)] : null;
                if (($v ? (float) $v->fresh()->quantity : (float) $p->fresh()->quantity) < 3) {
                    continue;
                }
                $qty = mt_rand(1, 10) > 8 ? 2 : 1;
                $sale = new Sale([
                    'tenant_id' => $t->id, 'product_id' => $p->id, 'variation_id' => $v?->id, 'customer_id' => mt_rand(1, 10) > 3 ? $customers[mt_rand(0, count($customers) - 1)]->id : null,
                    'quantity' => $qty, 'unit_price' => $v ? (float) $v->unit_price : (float) $p->unit_price, 'discount' => 0,
                    'sale_date' => $day, 'payment_method' => $methods[mt_rand(0, count($methods) - 1)],
                ]);
                $sale->tenant_id = $t->id;
                $sale->created_at = $day->copy()->setTime(mt_rand(9, 18), mt_rand(0, 59));
                $sale->save();
                $sales++;
            }
        }

        // The online shop, switched on and filled in.
        $shop = Shop::updateOrCreate(['tenant_id' => $t->id], [
            'slug' => 'akosua-boutique', 'enabled' => true, 'in_marketplace' => true, 'pickup' => true, 'delivery' => true,
            'tagline' => 'Wigs, styling and fashion in Osu, Accra', 'city' => 'Osu, Accra', 'whatsapp' => '0200000000', 'notify_phone' => '0200000000',
            'hours' => 'Mon–Sat, 9am–7pm', 'delivery_note' => 'Delivery within Accra, fee agreed on call',
            'about' => "Welcome to Akosua's Boutique. We sell quality wigs and hair, then install them beautifully. Ask about our home visits.",
            'momo_network' => 'MTN', 'momo_number' => '0200000000', 'momo_name' => "Akosua's Boutique",
            'bookings_enabled' => true, 'booking_notice_hours' => 12, 'booking_max_days' => 30, 'booking_buffer_minutes' => 30,
        ]);

        $wig = $products['Brazilian Body Wave Wig'][0];
        $services = collect([
            ['Wig installation', 'A natural-looking hairline, cut, styled and fixed. Bring your wig or buy one from us.', 150, true, 120, 'both', 'Within Accra, transport added', [$wig->id, $products['HD Lace Frontal Wig 18"'][0]->id]],
            ['Frontal fixing & styling', 'Frontal plucked, bleached knots, laid and styled.', 200, true, 150, 'shop', null, []],
            ['Knotless braids', 'Neat knotless braids, any length. Braiding hair sold separately.', 250, true, 240, 'shop', null, [$products['Xpression Braiding Hair'][0]->id]],
        ])->map(function ($s, $i) use ($t, $shop) {
            $svc = new ShopService(['shop_id' => $shop->id, 'name' => $s[0], 'description' => $s[1], 'price' => $s[2], 'price_from' => $s[3], 'duration_minutes' => $s[4],
                'location' => $s[5], 'travel_note' => $s[6], 'product_ids' => $s[7] ?: null, 'active' => true, 'position' => $i]);
            $svc->tenant_id = $t->id;
            $svc->save();

            return $svc;
        });

        $this->orders($t, $shop, $products, $customers);
        $this->bookings($t, $shop, $services);
        $this->visits($t, $shop, $products);

        $staff = $this->user('Kojo Asare', 'kojo@demo.zinnvy.test', 'Sales');
        $staff->forceFill(['first_login' => 1])->save();

        Artisan::call('inventory:forecast', ['--window' => 30]);
        $this->command?->info("Full demo ready: {$sales} sales, tenant {$t->id}.");
    }

    // ---------------------------------------------------------------- pieces

    private function orders(Tenant $t, Shop $shop, array $products, array $customers): void
    {
        $plan = [
            // days ago, status, customer idx, [[product, qty]], fulfilment, utm_source
            [0, 'new', 0, [['Brazilian Body Wave Wig', 1, 'Natural Black / 20"']], 'delivery', 'whatsapp'],
            [0, 'new', 4, [['Beaded Bracelet', 2], ['Cowrie Shell Necklace', 1]], 'pickup', 'instagram'],
            [1, 'contacted', 2, [['Ankara Wrap Dress', 1, 'M']], 'delivery', 'whatsapp'],
            [2, 'confirmed', 6, [['Hair Growth Oil 100ml', 2], ['Lip Gloss Set (3)', 1]], 'pickup', 'facebook'],
            [4, 'completed', 1, [['HD Lace Frontal Wig 18"', 1]], 'pickup', 'whatsapp'],
            [6, 'completed', 3, [['Xpression Braiding Hair', 4]], 'pickup', 'instagram'],
            [9, 'completed', 7, [['Leather Sandals', 1, 'Brown / 42']], 'delivery', 'tiktok'],
            [12, 'completed', 5, [['Kente Print Shirt', 1, 'L'], ['Beaded Bracelet', 1]], 'pickup', 'whatsapp'],
        ];
        foreach ($plan as $i => [$ago, $status, $ci, $lines, $ful, $src]) {
            $c = $customers[$ci];
            $items = collect($lines)->map(function ($l) use ($products) {
                [$p, $vars] = $products[$l[0]];
                $v = isset($l[2]) ? collect($vars)->firstWhere('name', $l[2]) : null;
                $price = $v ? (float) $v->unit_price : (float) $p->unit_price;

                return ['product_id' => $p->id, 'variation_id' => $v?->id, 'name' => $p->name, 'variation_name' => $v?->name, 'quantity' => $l[1], 'unit_price' => $price, 'line_total' => $price * $l[1]];
            });
            $at = Carbon::now()->subDays($ago)->subHours(mt_rand(0, 6));
            $o = new ShopOrder([
                'shop_id' => $shop->id, 'number' => 1001 + $i, 'status' => $status, 'customer_name' => $c->name, 'customer_phone' => $c->phone,
                'fulfilment' => $ful, 'address' => $ful === 'delivery' ? 'East Legon, near the mall' : null, 'subtotal' => $items->sum('line_total'),
                'item_count' => $items->sum('quantity'), 'source' => ['utm_source' => $src, 'utm_medium' => 'social'], 'visitor_id' => 'wt-order-'.$i,
                'contacted_at' => in_array($status, ['contacted', 'confirmed', 'completed']) ? $at->copy()->addHour() : null,
                'completed_at' => $status === 'completed' ? $at->copy()->addDay() : null, 'customer_id' => $status === 'completed' ? $c->id : null,
            ]);
            $o->tenant_id = $t->id;
            $o->created_at = $at;
            $o->save();
            $o->items()->createMany($items->all());
        }
    }

    private function bookings(Tenant $t, Shop $shop, $services): void
    {
        $plan = [
            // days from now, hour, minutes, status, service idx, customer, location, address, note, for product
            [1, 10, 0, 'confirmed', 0, 'Esi Quaye', 'home', 'Airport Residential, near the Marina Mall', 'Middle part please', 'Brazilian Body Wave Wig'],
            [1, 14, 0, 'requested', 1, 'Maame Serwaa', 'shop', null, null, null],
            [2, 11, 30, 'requested', 0, 'Akua Sarpong', 'shop', null, "I'll bring my own wig", null],
            [3, 9, 0, 'confirmed', 2, 'Adwoa Frimpong', 'shop', null, 'Shoulder length please', 'Xpression Braiding Hair'],
            [-2, 13, 0, 'completed', 0, 'Abena Owusu', 'home', 'Dzorwulu, Accra', null, null],
        ];
        foreach ($plan as $i => [$days, $h, $m, $status, $si, $name, $loc, $addr, $note, $for]) {
            $s = $services[$si];
            $start = Carbon::today()->addDays($days)->setTime($h, $m);
            $b = new Booking([
                'shop_id' => $shop->id, 'service_id' => $s->id, 'number' => 1001 + $i, 'status' => $status, 'service_name' => $s->name, 'price' => $s->price,
                'duration_minutes' => $s->duration_minutes, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($s->duration_minutes), 'location' => $loc,
                'address' => $addr, 'for_product' => $for, 'customer_name' => $name, 'customer_phone' => '020000'.str_pad((string) (50 + $i), 4, '0', STR_PAD_LEFT),
                'note' => $note, 'confirmed_at' => $status === 'requested' ? null : now(),
            ]);
            $b->tenant_id = $t->id;
            $b->save();
        }
    }

    /** Thirty days of anonymous shop visits: growing traffic, WhatsApp-heavy, with a funnel and a few misses. */
    private function visits(Tenant $t, Shop $shop, array $products): void
    {
        $ids = collect($products)->map(fn ($x) => $x[0]->id)->values();
        $hot = [$ids[0], $ids[0], $ids[0], $ids[1], $ids[4], $ids[6], $ids[5], $ids[2], $ids[9]];
        $sources = ['whatsapp' => 46, 'instagram' => 24, 'direct' => 12, 'facebook' => 8, 'tiktok' => 6, 'google' => 2, 'marketplace' => 2];
        $devices = ['mobile' => 74, 'desktop' => 23, 'tablet' => 3];
        $searches = [['closure wig', 0], ['braiding hair', 1], ['lace front', 2], ['ankara dress', 1], ['wedding wig', 0]];
        $rows = [];
        for ($d = 29; $d >= 0; $d--) {
            $visitors = (int) round(4 + (29 - $d) * 0.55 + mt_rand(0, 6));
            for ($v = 0; $v < $visitors; $v++) {
                $vid = 'wt-v-'.$d.'-'.$v.'-'.Str::random(4);
                $src = $this->weighted($sources);
                $dev = $this->weighted($devices);
                $at = Carbon::now()->subDays($d)->setTime(mt_rand(7, 22), mt_rand(0, 59), mt_rand(0, 59));
                $base = ['shop_id' => $shop->id, 'visitor_id' => $vid, 'source' => $src, 'device' => $dev, 'created_at' => $at, 'ip_hash' => null, 'campaign' => null, 'search' => null, 'results' => null, 'product_id' => null];
                $rows[] = ['type' => 'view'] + $base;
                if (mt_rand(1, 100) <= 14) {
                    [$term, $res] = $searches[mt_rand(0, count($searches) - 1)];
                    $rows[] = ['type' => 'search', 'search' => $term, 'results' => $res] + $base;
                }
                if (mt_rand(1, 100) > 38) {
                    $pid = $hot[mt_rand(0, count($hot) - 1)];
                    $rows[] = ['type' => 'product_view', 'product_id' => $pid] + $base;
                    if (mt_rand(1, 100) <= 28) {
                        $rows[] = ['type' => 'add_to_cart', 'product_id' => $pid] + $base;
                        if (mt_rand(1, 100) <= 45) {
                            $rows[] = ['type' => 'checkout'] + $base;
                        }
                    }
                    if (mt_rand(1, 100) <= 7) {
                        $rows[] = ['type' => 'chat'] + $base;
                    }
                    if (mt_rand(1, 100) <= 4) {
                        $rows[] = ['type' => 'share'] + $base;
                    }
                }
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            ShopEvent::insert($chunk);
        }
        // The orders above came from visitors that also appear in the funnel.
        foreach (ShopOrder::withoutGlobalScopes()->where('shop_id', $shop->id)->get() as $o) {
            ShopEvent::insert([['shop_id' => $shop->id, 'type' => 'view', 'visitor_id' => $o->visitor_id, 'source' => $o->source['utm_source'] ?? 'direct', 'device' => 'mobile', 'created_at' => $o->created_at->copy()->subMinutes(20),
                'product_id' => null, 'campaign' => null, 'search' => null, 'results' => null, 'ip_hash' => null],
                ['shop_id' => $shop->id, 'type' => 'checkout', 'visitor_id' => $o->visitor_id, 'source' => $o->source['utm_source'] ?? 'direct', 'device' => 'mobile', 'created_at' => $o->created_at->copy()->subMinutes(3),
                    'product_id' => null, 'campaign' => null, 'search' => null, 'results' => null, 'ip_hash' => null]]);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function user(string $name, string $email, string $role): User
    {
        $u = User::where('email', $email)->first() ?? new User();
        // No real password: sign-in for the capture uses a session token, never a login form.
        $u->forceFill(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(40)), 'tenant_id' => $this->tenant->id, 'role' => $role, 'first_login' => 0])->save();

        return $u;
    }

    private function wipeData(): void
    {
        $id = $this->tenant->id;
        $shopIds = DB::table('shops')->where('tenant_id', $id)->pluck('id');
        DB::table('shop_events')->whereIn('shop_id', $shopIds)->delete();
        $orderIds = DB::table('shop_orders')->where('tenant_id', $id)->pluck('id');
        DB::table('shop_order_items')->whereIn('shop_order_id', $orderIds)->delete();
        foreach (['bookings', 'shop_orders', 'shop_services', 'return_items', 'sales', 'product_forecasts', 'product_variations', 'product_images', 'product_videos', 'products', 'categories', 'customers'] as $table) {
            DB::table($table)->where('tenant_id', $id)->delete();
        }
    }

    private function reset(): void
    {
        if (! $this->tenant) {
            return;
        }
        $this->wipeData();
        $id = $this->tenant->id;
        DB::table('shops')->where('tenant_id', $id)->delete();
        foreach (['sms_logs', 'sms_credits', 'token_transactions', 'tenant_tokens', 'daily_login_rewards', 'audit_logs', 'subscription_histories', 'integration_api_keys', 'store_connections'] as $table) {
            DB::table($table)->where('tenant_id', $id)->delete();
        }
        $userIds = DB::table('users')->where('tenant_id', $id)->pluck('id');
        DB::table('auth_refresh_tokens')->whereIn('user_id', $userIds)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
        DB::table('users')->where('tenant_id', $id)->delete();
        Tenant::whereKey($id)->delete();
        $this->tenant = null;
    }

    private function pick(array $weights): int
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $i => $w) {
            if (($r -= $w) <= 0) {
                return $i;
            }
        }

        return 0;
    }

    private function weighted(array $map): string
    {
        $r = mt_rand(1, array_sum($map));
        foreach ($map as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }

        return array_key_first($map);
    }
}
