<?php

namespace App\Console\Commands;

use App\Models\StoreConnection;
use App\Services\Integrations\StoreSyncRunner;
use Illuminate\Console\Command;

/** Safety net behind the webhooks: re-pull every connected store's catalogue so stock can't drift. */
class SyncStores extends Command
{
    protected $signature = 'stores:sync';
    protected $description = 'Re-sync products from every connected Shopify / WooCommerce store';

    public function handle(StoreSyncRunner $runner): int
    {
        StoreConnection::where('status', 'active')->each(fn ($c) => $runner->syncProducts($c));

        return self::SUCCESS;
    }
}
