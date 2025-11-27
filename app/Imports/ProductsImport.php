<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

class ProductsImport implements ToCollection, WithHeadingRow
{
    protected int $tenantId;
    protected int $limit;

    protected int $currentProductCount;

    public function __construct(int $tenantId, int $limit)
    {
        $this->tenantId = $tenantId;
        $this->limit = $limit;
        $this->currentProductCount = Product::where('tenant_id', $this->tenantId)->count();
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // Skip totally empty rows
            if (!$this->rowHasData($row)) {
                continue;
            }

            // Check total product limit before creating each product
            if ($this->limit !== null && $this->currentProductCount >= $this->limit) {
                Log::warning('Product import skipped due to product limit', [
                    'tenant_id' => $this->tenantId,
                    'row' => $row,
                    'max_products' => $this->limit,
                ]);
                // break;
                continue;
            }

            try {
                $variations = null;

                if (!empty($row['variations'])) {
                    // user typed/pasted JSON into variations column
                    $decoded = json_decode($row['variations'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $variations = $decoded;
                    }
                }

                Product::create([
                    'tenant_id' => $this->tenantId,
                    'name' => $row['name'] ?? 'Unnamed Product',
                    'sku' => $row['sku'] ?? null,
                    'description' => $row['description'] ?? null,
                    'quantity' => (int) ($row['quantity'] ?? 0),
                    'unit_price' => (float) ($row['unit_price'] ?? 0),
                    'size' => $row['size'] ?? null,
                    'variations' => $variations,
                ]);

                $this->currentProductCount++;
            } catch (\Throwable $e) {
                Log::error('Product import failed for row', [
                    'row' => $row,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function rowHasData($row): bool
    {
        return collect($row)->filter(function ($value) {
            return !is_null($value) && $value !== '';
        })->isNotEmpty();
    }
}
