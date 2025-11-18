<?php

namespace App\Services\Ecommerce;

use App\Models\StoreConnection;
use App\Services\Ecommerce\Interfaces\StoreSyncInterface;

class StoreSyncFactory
{
    public static function make(StoreConnection $connection): ?StoreSyncInterface
    {
        return match ($connection->provider) {
            'shopify' => new ShopifyStoreSync(),
            'custom' => new CustomStoreSync(),
            default => new CustomStoreSync(), // fallback — or return null to fail fast
        };
    }
}
