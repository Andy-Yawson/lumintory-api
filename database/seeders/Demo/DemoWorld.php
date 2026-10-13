<?php

namespace Database\Seeders\Demo;

use App\Models\Backup;
use App\Models\Referral;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Orchestrates the demo tenants and keeps track of the logins created. */
class DemoWorld
{
    public const DOMAIN_SUFFIX = '.demo.zinnvy.test';

    /** Tables that carry a tenant_id and belong to a demo tenant. */
    private const TENANT_TABLES = [
        'return_items', 'sales', 'product_forecasts', 'product_variations', 'products', 'categories',
        'customers', 'sms_logs', 'sms_credits', 'token_transactions', 'tenant_tokens', 'daily_login_rewards',
        'integration_api_keys', 'store_connections', 'audit_logs', 'subscription_histories',
    ];

    /** @var array<int, array{email: string, role: string, tenant: string, password: string}> */
    private array $logins = [];

    /** @var array<string, Tenant> keyed by profile key */
    private array $tenants = [];

    private int $created = 0;

    /** The owner's real identity, kept across a DEMO_RESEED purge (deleting a tenant cascades to its users). */
    private ?array $preserved = null;

    public function __construct(
        private readonly ?string $password,
        private readonly ?string $ownerEmail,
        private readonly ?Command $command,
    ) {
    }

    public function purge(): void
    {
        $ids = Tenant::where('domain', 'like', '%'.self::DOMAIN_SUFFIX)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        if ($this->ownerEmail && ($owner = User::where('email', $this->ownerEmail)->whereIn('tenant_id', $ids)->first())) {
            $this->preserved = ['name' => $owner->name, 'zinnvy_sub' => $owner->zinnvy_sub];
        }

        DB::transaction(function () use ($ids) {
            $ticketIds = DB::table('support_tickets')->whereIn('tenant_id', $ids)->pluck('id');
            DB::table('support_ticket_messages')->whereIn('ticket_id', $ticketIds)->delete();
            DB::table('support_tickets')->whereIn('tenant_id', $ids)->delete();
            DB::table('referrals')->whereIn('referrer_tenant_id', $ids)->orWhereIn('referred_tenant_id', $ids)->delete();
            foreach (self::TENANT_TABLES as $table) {
                DB::table($table)->whereIn('tenant_id', $ids)->delete();
            }
            $userIds = DB::table('users')->whereIn('tenant_id', $ids)->pluck('id');
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('users')->whereIn('tenant_id', $ids)->delete();
            Backup::where('meta', 'like', '%"demo":true%')->delete();
            Tenant::whereIn('id', $ids)->delete();
        });

        $this->command?->info("Purged {$ids->count()} demo tenant(s).");
    }

    /** The platform tenant that owns the SuperAdmin used by the admin console. */
    public function platform(): void
    {
        $tenant = $this->firstOrCreateTenant([
            'key' => 'platform', 'name' => 'Zinnvy Platform (demo)', 'plan' => 'custom', 'currency' => 'GHS', 'symbol' => 'GH₵',
        ]);
        if (! $tenant) {
            return;
        }

        $this->makeUser($tenant, 'Demo SuperAdmin', 'superadmin', 'SuperAdmin');
        $this->makeUser($tenant, 'Demo Support', 'support', 'Support');
    }

    public function tenant(array $profile): void
    {
        $tenant = $this->firstOrCreateTenant($profile);
        if (! $tenant) {
            return;
        }

        (new DemoTenantBuilder($this, $tenant, $profile))->build();
    }

    /** @return Tenant|null null when it already exists (skipped) */
    private function firstOrCreateTenant(array $profile): ?Tenant
    {
        $domain = $profile['key'].self::DOMAIN_SUFFIX;
        if ($existing = Tenant::where('domain', $domain)->first()) {
            $this->tenants[$profile['key']] = $existing;
            $this->command?->line("  = {$profile['name']} already seeded, skipped");

            return null;
        }

        $tenant = Tenant::create([
            'name' => $profile['name'],
            'domain' => $domain,
            'plan' => $profile['plan'],
            'is_active' => $profile['active'] ?? true,
            'subscription_ends_at' => $profile['ends_at'] ?? now()->addMonths(10),
            'settings' => [
                'currency' => $profile['currency'],
                'currency_symbol' => $profile['symbol'],
                'low_stock_threshold' => 10,
            ],
            'last_active_at' => now()->subHours(3),
        ]);

        $this->tenants[$profile['key']] = $tenant;
        $this->created++;
        $this->command?->info("  + {$profile['name']} ({$profile['plan']})");

        return $tenant;
    }

    public function makeUser(Tenant $tenant, string $name, string $handle, string $role, ?string $email = null): User
    {
        $email ??= $handle.'@demo.zinnvy.test';
        $password = $this->password ?? Str::random(16);

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'tenant_id' => $tenant->id, 'role' => $role, 'first_login' => false],
        );
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->logins[] = ['email' => $email, 'role' => $role, 'tenant' => $tenant->name, 'password' => $password];

        return $user;
    }

    /**
     * DEMO_OWNER_EMAIL becomes an Administrator of Ama's Market, so a real Zinnvy sign-in
     * lands in the fully populated workspace. Works whether or not the demo tenants already
     * exist, and never resets anyone's password.
     *
     * An email is a single Inventory user, so if that person already belongs to ANOTHER
     * workspace they are MOVED here (that workspace and its data stay, but lose that
     * administrator). A SuperAdmin is never moved.
     */
    public function attachOwner(): void
    {
        $ama = $this->tenants['ama-market'] ?? null;
        if (! $this->ownerEmail || ! $ama) {
            return;
        }

        $user = User::where('email', $this->ownerEmail)->first();

        if (! $user) {
            $owner = $this->makeUser($ama, $this->preserved['name'] ?? 'Workspace Owner', 'owner', 'Administrator', $this->ownerEmail);
            if ($this->preserved) {
                $owner->forceFill(['zinnvy_sub' => $this->preserved['zinnvy_sub']])->save(); // stays linked to their Zinnvy account
            }
            $this->command?->info("  + {$this->ownerEmail} added as Administrator of {$ama->name}");

            return;
        }

        if ($user->tenant_id === $ama->id) {
            return;
        }

        if ($user->role === 'SuperAdmin') {
            $this->command?->warn("  ! {$this->ownerEmail} is a SuperAdmin; not moved into {$ama->name}.");

            return;
        }

        $from = $user->tenant_id;
        $user->forceFill(['tenant_id' => $ama->id, 'role' => 'Administrator', 'first_login' => false])->save();
        $user->tokens()->delete(); // next sign-in resolves the new workspace
        $this->command?->warn("  ~ {$this->ownerEmail} MOVED from workspace #{$from} into {$ama->name}. To undo: UPDATE users SET tenant_id = {$from} WHERE email = '{$this->ownerEmail}';");
    }

    public function ownerEmail(): ?string
    {
        return $this->ownerEmail;
    }

    /** Ama referred Lagos Threads (shows up in Rewards). */
    public function linkReferrals(): void
    {
        $ama = $this->tenants['ama-market'] ?? null;
        $lagos = $this->tenants['lagos-threads'] ?? null;
        if (! $ama || ! $lagos || Referral::where('referred_tenant_id', $lagos->id)->exists()) {
            return;
        }

        $lagos->forceFill(['referred_by_tenant_id' => $ama->id])->save();
        Referral::create(['referrer_tenant_id' => $ama->id, 'referred_tenant_id' => $lagos->id, 'tokens_awarded' => 10]);
    }

    /** Platform-level backup history (rows only — no files are created). */
    public function backups(): void
    {
        if (Backup::where('meta', 'like', '%"demo":true%')->exists()) {
            return;
        }

        $rows = [
            ['completed', 48_221_184, now()->subDays(2), 'backups/demo-db-1.sql.gz'],
            ['completed', 47_905_792, now()->subDay(), 'backups/demo-db-2.sql.gz'],
            ['failed', 0, now()->subHours(9), null],
            ['queued', 0, now()->subMinutes(5), null],
        ];
        foreach ($rows as [$status, $size, $at, $path]) {
            Backup::create([
                'disk' => 'local', 'path' => $path, 'size_bytes' => $size, 'type' => 'database', 'status' => $status,
                'meta' => ['demo' => true, 'initiated_by' => 'seeder'], 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    public function report(): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->newLine();
        $this->command->info($this->created ? "Demo data ready ({$this->created} tenant(s) created)." : 'Demo data was already in place.');

        if ($this->logins) {
            $this->command->warn('Demo logins (shown once — store them somewhere safe):');
            $this->command->table(
                ['Email', 'Role', 'Workspace', 'Password'],
                array_map(fn ($l) => [$l['email'], $l['role'], $l['tenant'], $l['password']], $this->logins),
            );
        }
    }
}
