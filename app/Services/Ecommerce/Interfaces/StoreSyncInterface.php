<?php

namespace App\Services\Ecommerce\Interfaces;

use App\Models\StoreConnection;

interface StoreSyncInterface
{
    public function syncProducts(StoreConnection $connection, bool $force = false): array;

    public function handleOrderWebhook(StoreConnection $connection, array $payload): void;

    public function verifyWebhook(StoreConnection $connection, string $body, array $headers): bool;
}
