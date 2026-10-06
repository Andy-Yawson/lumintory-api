<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spreadsheet → products. Shared by the guided "map your columns" import and
 * the plain template import, so both go through one set of rules.
 *
 * Rows arrive as plain arrays of cell values; the caller says which column
 * index feeds which Zinnvy field (the "mapping"). That's what lets someone
 * bring the spreadsheet they already keep instead of reshaping it first.
 */
class ProductImportService
{
    /** field => [label, required, hint, header aliases (normalised)] */
    public const FIELDS = [
        'name' => ['label' => 'Product name', 'required' => true, 'hint' => 'What the product is called', 'aliases' => ['name', 'productname', 'product', 'item', 'itemname', 'title']],
        'sku' => ['label' => 'SKU / code', 'required' => false, 'hint' => 'Your own product code. Used to avoid duplicates.', 'aliases' => ['sku', 'code', 'productcode', 'itemcode', 'barcode', 'ref', 'reference', 'partno', 'partnumber']],
        'category' => ['label' => 'Category', 'required' => false, 'hint' => 'Created automatically if it does not exist', 'aliases' => ['category', 'type', 'group', 'department', 'productcategory']],
        'quantity' => ['label' => 'Quantity in stock', 'required' => false, 'hint' => 'Opening stock', 'aliases' => ['quantity', 'qty', 'stock', 'instock', 'onhand', 'stockqty', 'available', 'balance', 'units']],
        'unit_price' => ['label' => 'Selling price', 'required' => false, 'hint' => 'Price per unit', 'aliases' => ['unitprice', 'price', 'sellingprice', 'saleprice', 'retailprice', 'rate']],
        'size' => ['label' => 'Size / unit', 'required' => false, 'hint' => 'e.g. 500g, Large, 1L', 'aliases' => ['size', 'unit', 'uom', 'weight', 'pack', 'packsize']],
        'description' => ['label' => 'Description', 'required' => false, 'hint' => 'Free text', 'aliases' => ['description', 'desc', 'details', 'notes', 'note']],
        'variations' => ['label' => 'Variations', 'required' => false, 'hint' => 'Name:Price:Stock | Name:Price:Stock', 'aliases' => ['variations', 'variants', 'variation', 'options']],
    ];

    public static function fieldCatalogue(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $out[] = ['key' => $key, 'label' => $f['label'], 'required' => $f['required'], 'hint' => $f['hint']];
        }

        return $out;
    }

    /** Guess which column feeds each field, from the header text. field => column index. */
    public static function suggestMapping(array $headers): array
    {
        $normalised = array_map(fn ($h) => self::norm((string) $h), $headers);
        $mapping = [];
        $taken = [];

        // Exact alias match first, in alias priority order, so "unit_price"
        // wins over a vaguer "amount" column.
        foreach (self::FIELDS as $field => $meta) {
            foreach ($meta['aliases'] as $alias) {
                $idx = array_search($alias, $normalised, true);
                if ($idx !== false && ! in_array($idx, $taken, true)) {
                    $mapping[$field] = $idx;
                    $taken[] = $idx;
                    break;
                }
            }
        }

        return $mapping;
    }

    private static function norm(string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($v));
    }

    /** "1,200.50", "$12", "12,50" → float. Blank/garbage → null. */
    public static function number(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        $s = preg_replace('/[^0-9.,\-]/', '', (string) $v);
        if ($s === '' || $s === '-') {
            return null;
        }

        // "12,50" — a lone comma with 1–2 digits after it is a decimal comma.
        if (! str_contains($s, '.') && preg_match('/,\d{1,2}$/', $s) && substr_count($s, ',') === 1) {
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows     data rows (header excluded)
     * @param  array<string, int>             $mapping  field => column index
     * @param  string                         $duplicates 'skip' | 'update' (match on SKU)
     * @return array{created:int, updated:int, skipped:int, failed:int, errors:array<int, array{row:int, message:string}>}
     */
    public function import(int $tenantId, ?int $limit, array $rows, array $mapping, string $duplicates = 'skip', int $firstRowNumber = 2): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $count = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        foreach ($rows as $i => $row) {
            $rowNo = $firstRowNumber + $i;
            $get = fn (string $f) => isset($mapping[$f]) ? ($row[$mapping[$f]] ?? null) : null;

            if (! collect($row)->contains(fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue; // blank line
            }

            $name = trim((string) $get('name'));
            if ($name === '') {
                $this->fail($result, $rowNo, 'Product name is empty');
                continue;
            }

            $qty = self::number($get('quantity')) ?? 0;
            $price = self::number($get('unit_price')) ?? 0;
            if ($qty < 0 || $price < 0) {
                $this->fail($result, $rowNo, 'Quantity and price cannot be negative');
                continue;
            }

            $sku = trim((string) $get('sku')) ?: null;
            $existing = $sku
                ? Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('sku', $sku)->first()
                : null;

            if ($existing && $duplicates !== 'update') {
                $result['skipped']++;
                continue;
            }

            if (! $existing && $limit !== null && $count >= $limit) {
                $result['skipped']++;
                $result['errors'][] = ['row' => $rowNo, 'message' => "Product limit ({$limit}) reached on your plan"];
                continue;
            }

            DB::beginTransaction();
            try {
                $categoryId = null;
                $categoryName = trim((string) $get('category'));
                if ($categoryName !== '') {
                    $categoryId = Category::withoutGlobalScopes()->firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $categoryName]
                    )->id;
                }

                $attrs = array_filter([
                    'category_id' => $categoryId,
                    'name' => $name,
                    'sku' => $sku,
                    'description' => trim((string) $get('description')) ?: null,
                    'size' => trim((string) $get('size')) ?: null,
                ], fn ($v) => $v !== null);

                if ($existing) {
                    $existing->update($attrs + (isset($mapping['quantity']) ? ['quantity' => $qty] : []) + (isset($mapping['unit_price']) ? ['unit_price' => $price] : []));
                    $product = $existing;
                    $result['updated']++;
                } else {
                    $product = new Product($attrs + ['tenant_id' => $tenantId, 'quantity' => $qty, 'unit_price' => $price]);
                    $product->tenant_id = $tenantId;
                    $product->save();
                    $count++;
                    $result['created']++;
                }

                $this->variations($tenantId, $product, (string) $get('variations'));

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::warning('Product import row failed', ['row' => $rowNo, 'error' => $e->getMessage()]);
                // The create/update above was rolled back — take back its tally too.
                if (isset($product) && ! $existing) {
                    $result['created'] = max(0, $result['created'] - 1);
                    $count = max(0, $count - 1);
                } elseif ($existing) {
                    $result['updated'] = max(0, $result['updated'] - 1);
                }
                $this->fail($result, $rowNo, 'Could not save this row');
            }
            unset($product);
        }

        return $result;
    }

    private function fail(array &$result, int $row, string $message): void
    {
        $result['failed']++;
        if (count($result['errors']) < 50) {
            $result['errors'][] = ['row' => $row, 'message' => $message];
        }
    }

    /** "Large:110:20 | Medium:86:30" — drops any pasted "(Format: …)" hint. */
    private function variations(int $tenantId, Product $product, string $input): void
    {
        $input = trim(preg_replace('/\(.*?\)/', '', $input));
        if ($input === '') {
            return;
        }

        $total = 0;
        foreach (explode('|', $input) as $group) {
            $parts = array_map('trim', explode(':', $group));
            if ($parts[0] === '') {
                continue;
            }

            $qty = self::number($parts[2] ?? null) ?? 0;
            ProductVariation::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $product->id, 'name' => $parts[0]],
                ['unit_price' => self::number($parts[1] ?? null) ?? $product->unit_price, 'quantity' => $qty]
            );
            $total += $qty;
        }

        $product->update(['quantity' => $total]);
    }
}
