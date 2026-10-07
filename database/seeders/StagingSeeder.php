<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoTenantBuilder;
use Database\Seeders\Demo\DemoWorld;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end test data for the STAGING deploy only (the `develop` branch).
 *
 * Production runs `php artisan db:seed` on every deploy, and DatabaseSeeder is
 * intentionally empty, so none of this can reach production by accident.
 * This seeder additionally refuses to run unless SEED_DEMO_DATA=true is set in
 * the process environment — the develop workflow sets it inline; nothing else does.
 *
 *   SEED_DEMO_DATA=true php artisan db:seed --class='Database\Seeders\StagingSeeder'
 *
 * Optional environment:
 *   DEMO_PASSWORD     password for every demo user (otherwise a random one per
 *                     user is generated and printed once — never a default)
 *   DEMO_OWNER_EMAIL  makes this email an Administrator of "Ama's Market" (even if the demo
 *                     tenants already exist), so "Continue with Zinnvy" (which links by
 *                     verified email) lands in a fully populated workspace. If that email
 *                     already belongs to another workspace the user is MOVED (see DemoWorld::attachOwner)
 *   DEMO_RESEED=true  wipe the demo tenants first and rebuild them with fresh dates
 *
 * It is idempotent: demo tenants are recognised by their `*.demo.zinnvy.test`
 * domain and are skipped when they already exist. It never touches other tenants' data;
 * the one exception is DEMO_OWNER_EMAIL's user, who may be moved into the demo workspace.
 */
class StagingSeeder extends Seeder
{
    public function run(): void
    {
        if (getenv('SEED_DEMO_DATA') !== 'true') {
            $this->command?->warn('StagingSeeder skipped: SEED_DEMO_DATA is not "true". (This is expected on production.)');

            return;
        }

        $world = new DemoWorld(
            password: getenv('DEMO_PASSWORD') ?: null,
            ownerEmail: getenv('DEMO_OWNER_EMAIL') ?: null,
            command: $this->command,
        );

        Model::unguard(); // lets us backdate created_at on historical rows

        try {
            if (getenv('DEMO_RESEED') === 'true') {
                $world->purge();
            }

            DB::transaction(function () use ($world) {
                $world->platform();

                foreach (DemoTenantBuilder::profiles() as $profile) {
                    $world->tenant($profile);
                }

                $world->attachOwner();
                $world->linkReferrals();
                $world->backups();
            });
        } finally {
            Model::reguard();
            Auth::forgetUser();
        }

        $world->report();
    }
}
