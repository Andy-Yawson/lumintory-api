<?php

namespace Database\Seeders\Demo;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DailyLoginReward;
use App\Models\IntegrationApiKey;
use App\Models\Product;
use App\Models\ProductForecast;
use App\Models\ProductVariation;
use App\Models\ReturnItem;
use App\Models\Sale;
use App\Models\SmsCredit;
use App\Models\SmsLog;
use App\Models\StoreConnection;
use App\Models\SubscriptionHistory;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\TenantToken;
use App\Models\TokenTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds one demo workspace through the real models, so the app's own rules
 * (stock deduction on sale, customer totals, return restock) produce the data
 * instead of us hand-writing numbers that could disagree with them.
 *
 * Randomness is seeded per tenant: re-running yields the same shape of data.
 */
class DemoTenantBuilder
{
    private const METHODS = ['Mobile Money', 'Mobile Money', 'Cash', 'Cash', 'Card', 'Bank Transfer'];

    /** @var array<int, array{product: Product, final: int, variations: array, price: float}> */
    private array $catalog = [];

    /** @var array<int, int> product id => units sold */
    private array $sold = [];

    /** @var array<int, Customer> */
    private array $customers = [];

    /** @var array<int, Sale> */
    private array $sales = [];

    /** @var array<int, User> */
    private array $users = [];

    public function __construct(
        private readonly DemoWorld $world,
        private readonly Tenant $tenant,
        private readonly array $p,
    ) {
        mt_srand(crc32($p['key']));
    }

    public function build(): void
    {
        $this->users();
        Auth::setUser($this->users[0]); // TenantScope + Product::creating read the acting user's tenant

        $this->categoriesAndProducts();
        $this->customers();
        $this->sales();
        $this->returns();
        $this->settleStock();
        $this->forecasts();
        $this->engagement();
        $this->integrations();
        $this->support();
        $this->audit();
        $this->history();
    }

    // ------------------------------------------------------------------ people

    private function users(): void
    {
        foreach ($this->p['users'] as [$name, $handle, $role]) {
            $this->users[] = $this->world->makeUser($this->tenant, $name, $handle, $role);
        }

        // People who've been invited but haven't signed in with Zinnvy yet (Team page shows "Invited").
        foreach ($this->p['invited'] ?? [] as [$name, $handle, $role]) {
            $invitee = $this->world->makeUser($this->tenant, $name, $handle, $role);
            $invitee->forceFill(['first_login' => true])->save();
            $this->users[] = $invitee;
        }

        if (($this->p['owner'] ?? false) && $this->world->ownerEmail()) {
            $this->users[] = $this->world->makeUser($this->tenant, 'Workspace Owner', 'owner', 'Administrator', $this->world->ownerEmail());
        }
    }

    private function customers(): void
    {
        foreach ($this->p['customers'] as $i => [$name, $phone, $email]) {
            $this->customers[] = Customer::create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $this->p['city'].' · demo address '.($i + 1),
            ]);
        }
    }

    // ---------------------------------------------------------------- catalogue

    private function categoriesAndProducts(): void
    {
        $categories = [];
        foreach ($this->p['products'] as $row) {
            $categories[$row['cat']] ??= Category::create(['tenant_id' => $this->tenant->id, 'name' => $row['cat']]);
        }

        foreach ($this->p['products'] as $row) {
            // Stock is padded so historical sales can't fail; settleStock() sets the real final level afterwards.
            $final = $row['qty'];
            $variations = $row['variations'] ?? [];

            $product = Product::create([
                'tenant_id' => $this->tenant->id,
                'name' => $row['name'],
                'sku' => $row['sku'],
                'size' => $row['size'] ?? null,
                'unit_price' => $row['price'],
                'quantity' => 1000 + $final,
                'description' => $row['name'].' — demo product',
                'lead_time_days' => $row['lead'] ?? 5,
                'min_stock_threshold' => $row['min'] ?? 10,
                'category_id' => $categories[$row['cat']]->id,
            ]);

            $vars = [];
            foreach ($variations as $vName => $vQty) {
                $vars[] = [
                    'model' => ProductVariation::create([
                        'tenant_id' => $this->tenant->id,
                        'product_id' => $product->id,
                        'name' => $vName,
                        'sku' => $row['sku'].'-'.Str::upper($vName),
                        'quantity' => 500 + $vQty,
                        'unit_price' => $row['price'],
                    ]),
                    'final' => $vQty,
                ];
            }

            $this->catalog[] = ['product' => $product, 'final' => $final, 'variations' => $vars, 'price' => $row['price']];
            $this->sold[$product->id] = 0;
        }
    }

    // -------------------------------------------------------------------- sales

    private function sales(): void
    {
        [$min, $max] = $this->p['sales_per_day'];
        [$from, $to] = $this->p['sales_window'] ?? [29, 0];
        $n = count($this->catalog);

        for ($daysAgo = $from; $daysAgo >= $to; $daysAgo--) {
            $count = mt_rand($min, $max);
            if ($daysAgo === 0 && $max > 0) {
                $count = max($count, 2); // so "Sales today" is never empty on the dashboard
            }

            for ($i = 0; $i < $count; $i++) {
                // Skewed pick: earlier catalogue entries are the best sellers.
                $entry = $this->catalog[(int) floor($n * (mt_rand() / mt_getrandmax()) ** 1.7)];
                $qty = mt_rand(1, 3);
                $variation = $entry['variations'] ? $entry['variations'][array_rand($entry['variations'])]['model'] : null;
                $at = Carbon::now()->subDays($daysAgo)->setTime(mt_rand(8, 18), mt_rand(0, 59));

                $sale = Sale::create([
                    'tenant_id' => $this->tenant->id,
                    'product_id' => $entry['product']->id,
                    'variation_id' => $variation?->id,
                    'quantity' => $qty,
                    'unit_price' => $entry['price'],
                    'discount' => mt_rand(0, 11) === 0 ? round($entry['price'] * 0.1, 2) : 0,
                    'sale_date' => $at->toDateString(),
                    'customer_id' => $this->customers && mt_rand(0, 99) < 55 ? $this->customers[array_rand($this->customers)]->id : null,
                    'payment_method' => self::METHODS[array_rand(self::METHODS)],
                    'notes' => mt_rand(0, 9) === 0 ? 'Demo: customer asked for a gift wrap' : null,
                ]);

                DB::table('sales')->where('id', $sale->id)->update(['created_at' => $at, 'updated_at' => $at]);
                $this->sold[$entry['product']->id] += $qty;
                $this->sales[] = $sale;
            }
        }
    }

    private function returns(): void
    {
        $recent = array_slice($this->sales, -min(count($this->sales), 25));
        if (! $recent) {
            return;
        }

        $want = min($this->p['returns'], count($recent));
        if ($want < 1) {
            return;
        }

        foreach ((array) array_rand($recent, $want) as $key) {
            $sale = $recent[$key];
            $at = Carbon::parse($sale->sale_date)->addDay()->min(Carbon::now());

            $return = ReturnItem::create([
                'tenant_id' => $this->tenant->id,
                'sale_id' => $sale->id,
                'product_id' => $sale->product_id,
                'quantity' => 1,
                'refund_amount' => $sale->unit_price,
                'reason' => ['Damaged on delivery', 'Wrong size', 'Changed their mind', 'Faulty item'][mt_rand(0, 3)],
                'return_date' => $at->toDateString(),
                'customer_id' => $sale->customer_id,
                'refund_method' => $sale->payment_method,
            ]);
            DB::table('return_items')->where('id', $return->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }
    }

    /** Replace the padding with the intended final stock levels (some low, one out). */
    private function settleStock(): void
    {
        foreach ($this->catalog as $entry) {
            foreach ($entry['variations'] as $v) {
                DB::table('product_variations')->where('id', $v['model']->id)->update(['quantity' => $v['final']]);
            }
            DB::table('products')->where('id', $entry['product']->id)->update(['quantity' => $entry['final']]);
        }
    }

    // ---------------------------------------------------------------- forecasts

    private function forecasts(): void
    {
        $window = max(1, ($this->p['sales_window'][0] ?? 29) - ($this->p['sales_window'][1] ?? 0) + 1);

        foreach ($this->catalog as $entry) {
            $avg = round($this->sold[$entry['product']->id] / $window, 2);
            $final = $entry['final'];
            $min = $entry['product']->min_stock_threshold;
            $lead = $entry['product']->lead_time_days ?: 5;
            $days = $avg > 0 ? round($final / $avg, 2) : 999;
            $safety = (int) ceil($avg * 2);

            $risk = $final <= $min || $days <= 5 ? 'critical' : ($days <= 14 ? 'warning' : 'ok');

            ProductForecast::create([
                'tenant_id' => $this->tenant->id,
                'product_id' => $entry['product']->id,
                'window_days' => 30,
                'avg_daily_sales' => $avg,
                'predicted_days_to_stockout' => min($days, 999),
                'current_quantity' => $final,
                'stock_risk_level' => $risk,
                'forecasted_at' => now()->subHours(2),
                'reorder_point' => (int) ceil($avg * $lead + $safety),
                'safety_stock' => $safety,
            ]);
        }
    }

    // --------------------------------------------------- tokens, SMS, rewards

    private function engagement(): void
    {
        TenantToken::create(['tenant_id' => $this->tenant->id, 'balance' => $this->p['tokens']]);
        SmsCredit::create(['tenant_id' => $this->tenant->id, 'credits' => $this->p['sms_credits']]);
        // Yesterday, so today's daily reward is claimable in the UI.
        DailyLoginReward::create(['tenant_id' => $this->tenant->id, 'last_reward_date' => now()->subDay()->toDateString()]);

        $earned = 0;
        foreach (range(1, min(12, $this->p['tokens'] + 3)) as $i) {
            TokenTransaction::create([
                'tenant_id' => $this->tenant->id, 'type' => 'earn', 'source' => 'daily_login', 'amount' => 1,
                'created_at' => now()->subDays($i), 'updated_at' => now()->subDays($i),
            ]);
            $earned++;
        }
        TokenTransaction::create([
            'tenant_id' => $this->tenant->id, 'type' => 'redeem', 'source' => 'sms_credits', 'amount' => 5,
            'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6),
        ]);

        if (! $this->customers) {
            return;
        }
        $statuses = ['delivered', 'delivered', 'delivered', 'sent', 'queued', 'failed'];
        foreach (range(0, 7) as $i) {
            $c = $this->customers[$i % count($this->customers)];
            $status = $statuses[$i % count($statuses)];
            $at = now()->subDays($i)->subHours($i);
            SmsLog::create([
                'tenant_id' => $this->tenant->id,
                'recipient' => $c->phone,
                'message' => "Hi {$c->name}, thanks for shopping with {$this->tenant->name}! New stock arrives Friday.",
                'provider_message_id' => 'demo-'.Str::lower(Str::random(10)),
                'status' => $status,
                'segments' => 1,
                'cost' => 0.0475,
                'provider_response' => ['demo' => true, 'status' => $status],
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    // ------------------------------------------------------------ integrations

    private function integrations(): void
    {
        foreach ($this->p['api_keys'] as [$name, $active]) {
            IntegrationApiKey::create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'public_key' => 'int_demo_'.Str::lower(Str::random(32)),
                'secret' => Str::random(60),
                'scopes' => ['products:read', 'products:write', 'orders:write', 'ai:read'],
                'is_active' => $active,
            ]);
        }

        foreach ($this->p['stores'] as [$provider, $url, $status, $count, $error]) {
            StoreConnection::create([
                'tenant_id' => $this->tenant->id,
                'provider' => $provider,
                'store_url' => $url,
                'status' => $status,
                // Fake, clearly-demo credentials: syncing against them fails safely rather than hitting a real shop.
                'credentials' => ['access_token' => 'demo-not-a-real-token', 'demo' => true],
                'product_count' => $count,
                'last_synced_at' => $status === 'active' ? now()->subHours(5) : null,
                'last_error' => $error,
            ]);
        }
    }

    // ----------------------------------------------------------------- support

    private function support(): void
    {
        $staff = User::where('email', 'superadmin@demo.zinnvy.test')->first();
        $author = $this->users[0];

        foreach ($this->p['tickets'] as [$subject, $priority, $status, $category, $daysAgo, $thread]) {
            $at = now()->subDays($daysAgo);
            $ticket = SupportTicket::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $author->id,
                'subject' => $subject,
                'description' => $thread[0],
                'priority' => $priority,
                'status' => $status,
                'category' => $category,
                'assigned_to' => $status === 'open' ? null : $staff?->id,
                'last_reply_at' => $at->copy()->addHours(count($thread)),
                'created_at' => $at, 'updated_at' => $at,
            ]);

            foreach ($thread as $i => $body) {
                // Messages alternate: customer, staff, customer… ("!" prefix = internal staff note)
                $internal = str_starts_with($body, '!');
                $fromStaff = $internal || $i % 2 === 1;
                SupportTicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => ($fromStaff && $staff) ? $staff->id : $author->id,
                    'message' => ltrim($body, '!'),
                    'is_internal' => $internal,
                    'created_at' => $at->copy()->addHours($i), 'updated_at' => $at->copy()->addHours($i),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------- audit

    private function audit(): void
    {
        $events = [
            ['sales.store', 'POST', 'api/v1/sales', 201], ['products.update', 'PUT', 'api/v1/products/1', 200],
            ['customers.store', 'POST', 'api/v1/customers', 201], ['products.store', 'POST', 'api/v1/product', 201],
            ['returns.store', 'POST', 'api/v1/returns', 201], ['categories.store', 'POST', 'api/v1/categories', 201],
            ['sms.send', 'POST', 'api/v1/sms/send', 200], ['products.destroy', 'DELETE', 'api/v1/products/9', 200],
            ['sales.store', 'POST', 'api/v1/sales', 422], ['products.index', 'GET', 'api/v1/products', 200],
        ];

        for ($i = 0; $i < 40; $i++) {
            [$event, $method, $route, $status] = $events[array_rand($events)];
            $at = now()->subHours(mt_rand(1, 24 * 14));
            AuditLog::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $this->users[array_rand($this->users)]->id,
                'event' => $event,
                'method' => $method,
                'route' => $route,
                'controller' => Str::studly(Str::before($event, '.')).'Controller@'.Str::after($event, '.'),
                'ip_address' => '197.255.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                'user_agent' => 'Mozilla/5.0 (demo seeder)',
                'status_code' => $status,
                'duration_ms' => mt_rand(18, 420) + 0.5,
                'request' => ['demo' => true],
                'response' => ['demo' => true],
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    // ----------------------------------------------------------------- billing

    private function history(): void
    {
        foreach ($this->p['history'] as [$event, $from, $to, $amount, $monthsAgo]) {
            $at = now()->subMonths($monthsAgo);
            SubscriptionHistory::create([
                'tenant_id' => $this->tenant->id,
                'from_plan' => $from,
                'to_plan' => $to,
                'event_type' => $event,
                'amount' => $amount,
                'currency' => $this->p['currency'],
                'payment_reference' => $amount ? 'DEMO-'.Str::upper(Str::random(8)) : null,
                'gateway' => $amount ? 'paystack' : null,
                'effective_at' => $at,
                'meta' => ['demo' => true],
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    // ---------------------------------------------------------------- profiles

    /** @return array<int, array<string, mixed>> */
    public static function profiles(): array
    {
        return [
            [
                'key' => 'ama-market', 'name' => "Ama's Market", 'plan' => 'pro', 'currency' => 'GHS', 'symbol' => 'GH₵',
                'city' => 'Accra', 'owner' => true, 'tokens' => 7, 'sms_credits' => 84, 'returns' => 4, 'sales_per_day' => [1, 4],
                'users' => [['Ama Mensah', 'ama', 'Administrator'], ['Kofi Boateng', 'kofi', 'Sales']],
                'invited' => [['Nana Yaa Pending', 'nanayaa', 'Sales']],
                'customers' => [
                    ['Adwoa Mensah', '+233245550182', 'adwoa@example.com'], ['Kojo Asare', '+233207729011', null],
                    ['Efua Quaye', '+233241004455', 'efua@example.com'], ['Yaw Darko', '+233203318870', null],
                    ['Abena Owusu', '+233244120933', 'abena@example.com'], ['Nana Kwesi', '+233209775012', null],
                    ['Akosua Frimpong', '+233242885960', 'akosua@example.com'], ['Kwabena Tetteh', '+233205540218', null],
                    ['Esi Annan', '+233246173304', 'esi@example.com'], ['Fiifi Ansah', '+233208861477', null],
                ],
                'products' => [
                    ['name' => 'Palm Breeze Body Oil', 'sku' => 'PBO-100', 'cat' => 'Beauty', 'price' => 42, 'qty' => 6, 'min' => 10],
                    ['name' => 'Shea Butter 250ml', 'sku' => 'SB-250', 'cat' => 'Beauty', 'price' => 28, 'qty' => 3, 'min' => 10],
                    ['name' => 'African Black Soap Bar', 'sku' => 'BSB-040', 'cat' => 'Beauty', 'price' => 12, 'qty' => 58, 'min' => 15],
                    ['name' => 'Kente Print Shirt', 'sku' => 'KPS-204', 'cat' => 'Clothing', 'price' => 185, 'qty' => 24, 'min' => 8, 'variations' => ['S' => 5, 'M' => 9, 'L' => 7, 'XL' => 3]],
                    ['name' => 'Ankara Wrap Skirt', 'sku' => 'AWS-118', 'cat' => 'Clothing', 'price' => 150, 'qty' => 14, 'min' => 5],
                    ['name' => 'Beaded Hoop Earrings', 'sku' => 'BHE-011', 'cat' => 'Accessories', 'price' => 65, 'qty' => 31, 'min' => 10],
                    ['name' => 'Leather Sandals', 'sku' => 'LSN-330', 'cat' => 'Accessories', 'price' => 120, 'qty' => 17, 'min' => 6],
                    ['name' => 'Cowrie Shell Necklace', 'sku' => 'CSN-072', 'cat' => 'Accessories', 'price' => 55, 'qty' => 9, 'min' => 8],
                    ['name' => 'Gari 5kg', 'sku' => 'GRI-500', 'cat' => 'Groceries', 'price' => 38, 'qty' => 44, 'min' => 12],
                    ['name' => 'Palm Oil 1L', 'sku' => 'POL-001', 'cat' => 'Groceries', 'price' => 30, 'qty' => 8, 'min' => 10],
                    ['name' => 'Raw Honey 500g', 'sku' => 'RHN-500', 'cat' => 'Groceries', 'price' => 48, 'qty' => 26, 'min' => 8],
                    ['name' => 'Woven Basket', 'sku' => 'WBK-210', 'cat' => 'Home', 'price' => 80, 'qty' => 12, 'min' => 4],
                    ['name' => 'Clay Pot Set', 'sku' => 'CPS-044', 'cat' => 'Home', 'price' => 95, 'qty' => 0, 'min' => 3],
                    ['name' => 'Shea Candle', 'sku' => 'SCD-019', 'cat' => 'Home', 'price' => 35, 'qty' => 40, 'min' => 10],
                ],
                'api_keys' => [['Website checkout', true], ['Old POS (revoked)', false]],
                'stores' => [
                    ['shopify', 'amas-market-demo.myshopify.com', 'active', 12, null],
                    ['woocommerce', 'https://shop.amasmarket.example', 'error', 0, 'Demo: could not reach the store (HTTP 503).'],
                ],
                'tickets' => [
                    ['Receipt printing is cut off', 'medium', 'open', 'Bug', 3, [
                        'Receipts print with the bottom line cut off on our 58mm printer.',
                        'Thanks for flagging this — could you tell us the printer model?',
                        '!Likely the receipt template height; check the PDF viewport.',
                        'It is an XP-58IIH.',
                    ]],
                    ['How do I add a second branch?', 'low', 'resolved', 'How do I…', 12, [
                        'We are opening a second shop. Can one workspace cover both?',
                        'Yes — add a user for each branch and use categories to separate stock. Does that help?',
                        'Perfect, thank you!',
                    ]],
                    ['Invoice for last renewal', 'high', 'closed', 'Billing', 30, [
                        'Please resend the invoice for our last renewal.',
                        'Done — sent to ama@demo.zinnvy.test.',
                    ]],
                ],
                'history' => [['signup', 'basic', 'pro', null, 10], ['renewal', 'pro', 'pro', 290, 0]],
            ],
            [
                'key' => 'kwame-hardware', 'name' => 'Kwame Hardware', 'plan' => 'basic', 'currency' => 'GHS', 'symbol' => 'GH₵',
                'city' => 'Kumasi', 'tokens' => 2, 'sms_credits' => 5, 'returns' => 1, 'sales_per_day' => [0, 2],
                'users' => [['Kwame Asante', 'kwame', 'Administrator'], ['Efua Sarpong', 'efua', 'Sales']],
                'customers' => [
                    ['Mensah Builders', '+233244001122', null], ['Ofori Contractors', '+233208003344', 'ofori@example.com'],
                    ['Adjei Plumbing', '+233246005566', null], ['Walk-in regular', '+233201007788', null],
                ],
                'products' => [
                    ['name' => 'Cement 50kg', 'sku' => 'CEM-050', 'cat' => 'Building', 'price' => 95, 'qty' => 60, 'min' => 20],
                    ['name' => 'Roofing Nails 1kg', 'sku' => 'RFN-001', 'cat' => 'Building', 'price' => 22, 'qty' => 9, 'min' => 10],
                    ['name' => 'Paint 4L White', 'sku' => 'PNT-4W', 'cat' => 'Paint', 'price' => 160, 'qty' => 14, 'min' => 5],
                    ['name' => 'PVC Pipe 1in', 'sku' => 'PVC-100', 'cat' => 'Plumbing', 'price' => 35, 'qty' => 28, 'min' => 10],
                    ['name' => 'Padlock Heavy Duty', 'sku' => 'PDL-HD', 'cat' => 'Security', 'price' => 48, 'qty' => 4, 'min' => 6],
                    ['name' => 'Claw Hammer', 'sku' => 'HMR-016', 'cat' => 'Tools', 'price' => 55, 'qty' => 11, 'min' => 4],
                ],
                'api_keys' => [], 'stores' => [],
                'tickets' => [['Hit my product limit', 'medium', 'open', 'Billing', 1, [
                    'I cannot add more products on my plan. What are my options?',
                ]]],
                'history' => [['signup', null, 'basic', null, 4]],
            ],
            [
                'key' => 'lagos-threads', 'name' => 'Lagos Threads', 'plan' => 'pro', 'currency' => 'NGN', 'symbol' => '₦',
                'city' => 'Lagos', 'tokens' => 4, 'sms_credits' => 210, 'returns' => 2, 'sales_per_day' => [0, 3],
                'users' => [['Funke Adeyemi', 'funke', 'Administrator']],
                'customers' => [
                    ['Ngozi Okafor', '+2348031234567', 'ngozi@example.com'], ['Tunde Bakare', '+2348059876543', null],
                    ['Amaka Eze', '+2348021112233', 'amaka@example.com'], ['Seyi Coker', '+2348077665544', null],
                    ['Halima Bello', '+2348100112233', null],
                ],
                'products' => [
                    ['name' => 'Adire Fabric 6yd', 'sku' => 'ADF-006', 'cat' => 'Fabric', 'price' => 18500, 'qty' => 22, 'min' => 6],
                    ['name' => 'Ankara Gown', 'sku' => 'ANG-210', 'cat' => 'Ready-to-wear', 'price' => 32000, 'qty' => 7, 'min' => 5, 'variations' => ['M' => 3, 'L' => 3, 'XL' => 1]],
                    ['name' => 'Agbada Set', 'sku' => 'AGB-090', 'cat' => 'Ready-to-wear', 'price' => 75000, 'qty' => 5, 'min' => 3],
                    ['name' => 'Gele Headwrap', 'sku' => 'GEL-015', 'cat' => 'Accessories', 'price' => 9000, 'qty' => 35, 'min' => 10],
                    ['name' => 'Beaded Necklace', 'sku' => 'BNK-033', 'cat' => 'Accessories', 'price' => 12500, 'qty' => 19, 'min' => 8],
                    ['name' => 'Leather Bag', 'sku' => 'LBG-120', 'cat' => 'Accessories', 'price' => 41000, 'qty' => 2, 'min' => 4],
                    ['name' => 'Aso-Oke Cap', 'sku' => 'AOC-007', 'cat' => 'Accessories', 'price' => 7500, 'qty' => 27, 'min' => 8],
                    ['name' => 'Lace Fabric 5yd', 'sku' => 'LCF-005', 'cat' => 'Fabric', 'price' => 54000, 'qty' => 13, 'min' => 4],
                ],
                'api_keys' => [['Storefront sync', true]],
                'stores' => [['woocommerce', 'https://lagosthreads.example', 'active', 8, null]],
                'tickets' => [],
                'history' => [['signup', 'basic', 'pro', null, 5], ['renewal', 'pro', 'pro', 290, 1]],
            ],
            [
                'key' => 'dormant-bakery', 'name' => 'Dormant Bakery', 'plan' => 'basic', 'currency' => 'GHS', 'symbol' => 'GH₵',
                'city' => 'Tamale', 'active' => false, 'ends_at' => '2026-07-01', 'tokens' => 0, 'sms_credits' => 0, 'returns' => 0,
                'sales_per_day' => [0, 1], 'sales_window' => [70, 45],
                'users' => [['Yaw Boadu', 'yaw', 'Administrator']],
                'customers' => [['Auntie Serwaa', '+233243009911', null], ['Chop Bar Joe', '+233207004422', null]],
                'products' => [
                    ['name' => 'Sugar Bread', 'sku' => 'SGB-001', 'cat' => 'Bread', 'price' => 8, 'qty' => 30, 'min' => 10],
                    ['name' => 'Meat Pie', 'sku' => 'MTP-002', 'cat' => 'Pastry', 'price' => 12, 'qty' => 18, 'min' => 8],
                    ['name' => 'Birthday Cake 1kg', 'sku' => 'BDC-010', 'cat' => 'Cakes', 'price' => 180, 'qty' => 3, 'min' => 2],
                ],
                'api_keys' => [], 'stores' => [], 'tickets' => [],
                'history' => [['signup', null, 'basic', null, 12], ['cancel', 'basic', 'basic', null, 3]],
            ],
        ];
    }
}
