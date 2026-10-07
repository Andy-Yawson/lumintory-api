# Demo data for end-to-end testing (staging only)

`StagingSeeder` builds five demo workspaces through the real models, so stock, customer totals and
returns follow the app's own rules. It touches **every model table**.

| Workspace | Plan | Shows off |
|---|---|---|
| Ama's Market (GHS) | pro | ~70 sales over 30 days, variations, low + out-of-stock items, forecasts, returns, SMS log, API keys, a Shopify store (active) and a WooCommerce store (error), 3 support tickets (incl. an internal note), audit trail, rewards/tokens, a referral |
| Kwame Hardware (GHS) | basic | plan limits (2 users, small catalogue), an open "hit my limit" ticket |
| Lagos Threads (NGN ₦) | pro | a second currency, an active WooCommerce store, referred by Ama |
| Dormant Bakery | basic, **inactive**, expired | the `subscription_inactive` sign-in path |
| Zinnvy Platform (demo) | custom | owns the demo **SuperAdmin** + **Support** users for the admin console; also 4 backup rows |

## How it is kept out of production

* `DatabaseSeeder` is intentionally empty — production runs `db:seed` on every deploy.
* `StagingSeeder` does nothing unless `SEED_DEMO_DATA=true` is in the process environment.
* The deploy workflow sets that **inline, only when the branch is `develop`** (see `.github/workflows/deploy.yml`).
* Demo users all live at `*@demo.zinnvy.test`; demo tenants at `*.demo.zinnvy.test`. Other tenants are never touched.

## Passwords

There is **no default password**. Set a `DEMO_PASSWORD` repository secret, or leave it unset and
a random password per user is generated and printed once in the deploy log.
The demo set includes a SuperAdmin on an internet-reachable staging API, so use a strong secret.

Optional secret `DEMO_OWNER_EMAIL`: also adds that email as an Administrator of Ama's Market, so
"Continue with Zinnvy" (which links by verified email) lands in a fully populated workspace.

## Running it

```bash
SEED_DEMO_DATA=true php artisan db:seed --class='Database\Seeders\StagingSeeder'

# rebuild with fresh dates (wipes ONLY the demo tenants above)
SEED_DEMO_DATA=true DEMO_RESEED=true php artisan db:seed --class='Database\Seeders\StagingSeeder'
```

Re-running without `DEMO_RESEED` is a no-op for tenants that already exist.
