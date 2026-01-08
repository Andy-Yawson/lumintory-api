<?php

namespace App\Imports;

use App\Models\Category;
use App\Models\ProductVariation;
use DB;
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
                continue;
            }

            DB::beginTransaction();
            try {
                // 1. Handle Category (Find or Create)
                $categoryId = null;
                if (!empty($row['category'])) {
                    $categoryName = trim($row['category']);
                    $category = Category::where('tenant_id', $this->tenantId)
                        ->where('name', 'LIKE', $categoryName)
                        ->first();

                    if (!$category) {
                        $category = Category::create([
                            'tenant_id' => $this->tenantId,
                            'name' => $categoryName,
                        ]);
                    }
                    $categoryId = $category->id;
                }

                // 2. Create the Product
                // Initial quantity is 0; we will update it if variations exist
                $product = Product::create([
                    'tenant_id' => $this->tenantId,
                    'category_id' => $categoryId,
                    'name' => $row['name'] ?? 'Unnamed Product',
                    'sku' => $row['sku'] ?? null,
                    'description' => $row['description'] ?? null,
                    'quantity' => (float) ($row['quantity'] ?? 0),
                    'unit_price' => (float) ($row['unit_price'] ?? 0),
                    'size' => $row['size'] ?? null,
                ]);

                // 3. Handle Variations (Table-based)
                // We check for the column name (Excel handles headings with colons/pipes uniquely sometimes)
                $variationInput = $row['variations'] ?? null;

                if (!empty($variationInput)) {
                    $totalVariationQty = 0;

                    // Split groups by |
                    $groups = explode('|', $variationInput);

                    foreach ($groups as $group) {
                        // Split components by : (Name:Price:Stock)
                        $parts = explode(':', trim($group));

                        if (count($parts) >= 1) {
                            $vName = trim($parts[0]);
                            $vPrice = isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : $product->unit_price;
                            $vQty = isset($parts[2]) && $parts[2] !== '' ? (float) $parts[2] : 0;

                            ProductVariation::create([
                                'tenant_id' => $this->tenantId,
                                'product_id' => $product->id,
                                'name' => $vName,
                                'unit_price' => $vPrice,
                                'quantity' => $vQty,
                            ]);

                            $totalVariationQty += $vQty;
                        }
                    }

                    // If variations were added, update the main product quantity to the sum
                    if ($totalVariationQty > 0 || count($groups) > 0) {
                        $product->update(['quantity' => $totalVariationQty]);
                    }
                }

                DB::commit();
                $this->currentProductCount++;

            } catch (\Throwable $e) {
                DB::rollBack();
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
