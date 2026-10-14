<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Spreadsheet → past sales, for shops moving their sales history into Zinnvy.
 * Same "map your columns" flow as ProductImportService: rows are plain arrays,
 * the mapping says which column feeds which field.
 *
 * Imported sales are history by default (stock = 'keep'): they're recorded with
 * stock_deducted = false and don't touch today's stock. 'deduct' runs them through
 * the normal stock rules instead, for recent sales made after stock was entered.
 */
class SalesImportService
{
    public const FIELDS = [
        'date' => ['label' => 'Sale date', 'required' => false, 'hint' => 'When it was sold. Blank means today.', 'aliases' => ['date', 'saledate', 'transactiondate', 'orderdate', 'day', 'datesold', 'soldon', 'time', 'datetime']],
        'product' => ['label' => 'Product', 'required' => true, 'hint' => 'Matched to your products by SKU or name', 'aliases' => ['product', 'productname', 'item', 'itemname', 'name', 'description', 'goods', 'sku', 'code', 'itemcode', 'productcode']],
        'variation' => ['label' => 'Variation', 'required' => false, 'hint' => 'e.g. Red, Large. Must exist on the product.', 'aliases' => ['variation', 'variant', 'size', 'colour', 'color', 'option']],
        'quantity' => ['label' => 'Quantity', 'required' => true, 'hint' => 'How many were sold', 'aliases' => ['quantity', 'qty', 'units', 'qtysold', 'quantitysold', 'pcs', 'count', 'no']],
        'unit_price' => ['label' => 'Unit price', 'required' => false, 'hint' => 'Price of one. Worked out from the total if blank.', 'aliases' => ['unitprice', 'price', 'sellingprice', 'rate', 'priceeach', 'each', 'unitcost']],
        'total' => ['label' => 'Line total', 'required' => false, 'hint' => 'Amount for the line, after discount', 'aliases' => ['total', 'amount', 'linetotal', 'totalamount', 'value', 'subtotal', 'sales', 'revenue', 'amountpaid']],
        'discount' => ['label' => 'Discount', 'required' => false, 'hint' => 'Money off this line', 'aliases' => ['discount', 'disc', 'discountamount']],
        'payment_method' => ['label' => 'Payment method', 'required' => false, 'hint' => 'Cash, Mobile Money, Card…', 'aliases' => ['paymentmethod', 'payment', 'paidby', 'method', 'paymenttype', 'mode', 'paymentmode', 'tender']],
        'customer' => ['label' => 'Customer name', 'required' => false, 'hint' => 'Added to Customers if new', 'aliases' => ['customer', 'customername', 'client', 'buyer', 'clientname', 'soldto']],
        'phone' => ['label' => 'Customer phone', 'required' => false, 'hint' => 'Used to match existing customers', 'aliases' => ['phone', 'phonenumber', 'mobile', 'tel', 'telephone', 'contact', 'customerphone']],
        'notes' => ['label' => 'Notes', 'required' => false, 'hint' => 'Free text', 'aliases' => ['notes', 'note', 'remarks', 'comment', 'comments', 'memo', 'ref', 'reference', 'receiptno', 'invoiceno']],
    ];

    private const METHODS = [
        'Cash' => ['cash'],
        'Mobile Money' => ['momo', 'mobilemoney', 'mtn', 'mtnmomo', 'vodafonecash', 'telecel', 'telecelcash', 'airteltigo', 'atmoney', 'mpesa', 'mobile'],
        'Card' => ['card', 'pos', 'visa', 'mastercard', 'debitcard', 'creditcard'],
        'Bank Transfer' => ['bank', 'banktransfer', 'transfer', 'eft', 'cheque', 'check'],
        'Credit' => ['credit', 'oncredit', 'owing', 'debt', 'later'],
    ];

    public static function fieldCatalogue(): array
    {
        return collect(self::FIELDS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'required' => $f['required'], 'hint' => $f['hint']])->values()->all();
    }

    public static function suggestMapping(array $headers): array
    {
        $normalised = array_map(fn ($h) => self::norm((string) $h), $headers);
        $mapping = [];
        $taken = [];
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

    public static function paymentMethod(?string $v): string
    {
        $n = self::norm((string) $v);
        if ($n === '') {
            return 'Cash';
        }
        foreach (self::METHODS as $label => $aliases) {
            if ($n === self::norm($label) || in_array($n, $aliases, true)) {
                return $label;
            }
        }
        foreach (self::METHODS as $label => $aliases) {
            foreach ($aliases as $a) {
                if (strlen($a) > 3 && str_contains($n, $a)) {
                    return $label;
                }
            }
        }

        return 'Other';
    }

    /**
     * Whether slash/dash dates in this file are day-first. Looks for an unambiguous one
     * (a part above 12); otherwise day-first, which is how shops in Ghana write dates.
     */
    public static function detectDayFirst(array $rows, ?int $col): bool
    {
        if ($col === null) {
            return true;
        }
        foreach ($rows as $r) {
            $v = $r[$col] ?? null;
            if (is_string($v) && preg_match('#^\s*(\d{1,2})[/.\-](\d{1,2})[/.\-]\d{2,4}#', $v, $m)) {
                if ((int) $m[1] > 12) {
                    return true;
                }
                if ((int) $m[2] > 12) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Excel serial, "13/10/2026", "2026-10-13", "13 Oct 2026"… → Carbon, or null if unreadable. */
    public static function date(mixed $v, bool $dayFirst): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $n = (float) $v;

                return $n > 20000 && $n < 80000 ? Carbon::instance(ExcelDate::excelToDateTimeObject($n)) : null;
            }
            $s = trim((string) $v);
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})(.*)$#', $s, $m)) {
                [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                [$d, $mo] = $dayFirst ? [$a, $b] : [$b, $a];
                $y = $y < 100 ? 2000 + $y : $y;

                return checkdate($mo, $d, $y) ? Carbon::create($y, $mo, $d) : null;
            }

            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Applies every rule to every row without writing anything.
     * Returns one entry per non-blank row: what would be saved, or why not.
     */
    private function evaluate(int $tenantId, array $rows, array $mapping, string $missing, string $stock, int $firstRowNumber, ?int $productLimit = null): array
    {
        $newNames = [];
        $col = fn (array $row, string $f) => isset($mapping[$f]) ? ($row[$mapping[$f]] ?? null) : null;
        $dayFirst = self::detectDayFirst($rows, $mapping['date'] ?? null);

        $products = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'name', 'sku', 'unit_price', 'quantity']);
        $bySku = $products->filter(fn ($p) => $p->sku)->keyBy(fn ($p) => mb_strtolower(trim($p->sku)));
        $byName = $products->keyBy(fn ($p) => mb_strtolower(trim($p->name)));
        $productCount = $products->count();
        $variations = ProductVariation::withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'product_id', 'name', 'unit_price', 'quantity'])
            ->groupBy('product_id');
        // Running stock, so 'deduct' flags the row that would run out, not just the first.
        $stockLeft = [];

        $out = [];
        foreach ($rows as $i => $row) {
            if (! collect($row)->contains(fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;
            }
            $rowNo = $firstRowNumber + $i;
            $e = ['row' => $rowNo, 'ok' => true, 'reason' => null, 'new_product' => false];

            $productText = trim((string) $col($row, 'product'));
            $variationText = trim((string) $col($row, 'variation'));
            $qty = ProductImportService::number($col($row, 'quantity'));
            $price = ProductImportService::number($col($row, 'unit_price'));
            $total = ProductImportService::number($col($row, 'total'));
            $discount = ProductImportService::number($col($row, 'discount')) ?? 0;
            $rawDate = $col($row, 'date');
            $date = self::date($rawDate, $dayFirst);

            $key = mb_strtolower($productText);
            $product = $bySku->get($key) ?? $byName->get($key);
            $variation = null;
            if ($product && $variationText !== '') {
                // "Brown/42", "brown / 42" and "Brown / 42" are the same option combination.
                $vkey = fn ($t) => preg_replace('/\s*\/\s*/', '/', preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $t))));
                $variation = ($variations->get($product->id) ?? collect())->first(fn ($v) => $vkey($v->name) === $vkey($variationText));
            }

            // Price: given, else from the line total, else the product's own price.
            if ($price === null && $total !== null && $qty) {
                $price = round(($total + $discount) / $qty, 2);
            }
            $price ??= $variation?->unit_price ?? $product?->unit_price;

            $e += [
                'date' => ($date ?? now())->toDateString(),
                'product' => $productText ?: null,
                'product_id' => $product?->id,
                'variation' => $variationText ?: null,
                'variation_id' => $variation?->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $discount,
                'total' => $qty !== null && $price !== null ? round($qty * $price - $discount, 2) : null,
                'payment_method' => self::paymentMethod((string) $col($row, 'payment_method')),
                'customer' => trim((string) $col($row, 'customer')) ?: null,
                'phone' => trim((string) $col($row, 'phone')) ?: null,
                'notes' => trim((string) $col($row, 'notes')) ?: null,
            ];

            $fail = function (string $why) use (&$e) { $e['ok'] = false; $e['reason'] = $why; };

            if ($productText === '') {
                $fail('Product is empty');
            } elseif ($qty === null || $qty <= 0) {
                $fail('Quantity must be more than 0');
            } elseif ($price === null || $price < 0) {
                $fail('No price: add a unit price or line total column');
            } elseif ($discount < 0) {
                $fail('Discount cannot be negative');
            } elseif ($rawDate !== null && trim((string) $rawDate) !== '' && ! $date) {
                $fail('Could not read the date "'.trim((string) $rawDate).'"');
            } elseif ($date && $date->isAfter(now()->endOfDay())) {
                $fail('Date is in the future');
            } elseif (! $product && $missing !== 'create') {
                $fail('No product called "'.$productText.'"');
            } elseif ($product && $variationText !== '' && ! $variation) {
                $fail('"'.$product->name.'" has no variation "'.$variationText.'"');
            } elseif (! $product) {
                $e['new_product'] = true; // created with 0 stock on import
                if (! isset($newNames[$key]) && $productLimit !== null && $productCount + count($newNames) >= $productLimit) {
                    $fail("Product limit ({$productLimit}) reached on your plan");
                } elseif ($stock === 'deduct') {
                    $fail('New product has no stock to deduct from');
                } elseif ($variationText !== '') {
                    $fail('Variation given for a product that doesn\'t exist yet');
                }
            } elseif ($stock === 'deduct') {
                $sk = $variation ? "v{$variation->id}" : "p{$product->id}";
                $stockLeft[$sk] ??= (float) ($variation ? $variation->quantity : $product->quantity);
                if ($stockLeft[$sk] < $qty) {
                    $fail('Not enough stock to deduct ('.rtrim(rtrim(number_format($stockLeft[$sk], 2, '.', ''), '0'), '.').' left)');
                } else {
                    $stockLeft[$sk] -= $qty;
                }
            }

            if ($e['ok'] && $e['new_product']) {
                $newNames[$key] = true;
            }
            $out[] = $e;
        }

        return ['rows' => $out, 'day_first' => $dayFirst];
    }

    public function plan(int $tenantId, array $rows, array $mapping, string $missing = 'skip', string $stock = 'keep', ?int $productLimit = null, int $firstRowNumber = 2, int $maxRows = 100): array
    {
        $eval = $this->evaluate($tenantId, $rows, $mapping, $missing, $stock, $firstRowNumber, $productLimit);
        $ok = collect($eval['rows'])->where('ok', true);

        return [
            'summary' => [
                'import' => $ok->count(),
                'error' => count($eval['rows']) - $ok->count(),
                'new_products' => $ok->where('new_product', true)->pluck('product')->map(fn ($p) => mb_strtolower($p))->unique()->count(),
                'total_amount' => round($ok->sum('total'), 2),
                'first_date' => $ok->min('date'),
                'last_date' => $ok->max('date'),
            ],
            'total' => count($eval['rows']),
            'day_first' => $eval['day_first'],
            // Problems first, so they're visible without scrolling.
            'rows' => collect($eval['rows'])->sortBy(fn ($r) => [$r['ok'] ? 1 : 0, $r['row']])->take($maxRows)->values()->all(),
        ];
    }

    public function import(int $tenantId, array $rows, array $mapping, string $missing = 'skip', string $stock = 'keep', ?int $productLimit = null, ?int $customerLimit = null, int $firstRowNumber = 2): array
    {
        $eval = $this->evaluate($tenantId, $rows, $mapping, $missing, $stock, $firstRowNumber, $productLimit);
        $result = ['imported' => 0, 'failed' => 0, 'new_products' => 0, 'new_customers' => 0, 'total_amount' => 0.0, 'errors' => []];
        $newProducts = [];
        $customers = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'name', 'phone']);

        foreach ($eval['rows'] as $e) {
            if (! $e['ok']) {
                $this->fail($result, $e['row'], $e['reason']);
                continue;
            }

            try {
                DB::transaction(function () use ($tenantId, $e, $stock, $customerLimit, &$newProducts, &$customers, &$result) {
                    $productId = $e['product_id'];
                    if (! $productId) {
                        $k = mb_strtolower($e['product']);
                        if (! isset($newProducts[$k])) {
                            $p = new Product(['name' => $e['product'], 'unit_price' => $e['unit_price'], 'quantity' => 0]);
                            $p->tenant_id = $tenantId;
                            $p->save();
                            $newProducts[$k] = $p->id;
                            $result['new_products']++;
                        }
                        $productId = $newProducts[$k];
                    }

                    $customerId = null;
                    if ($e['customer'] || $e['phone']) {
                        $c = $this->findCustomer($customers, $e['customer'], $e['phone']);
                        if (! $c && ($customerLimit === null || $customers->count() < $customerLimit)) {
                            $c = Customer::withoutGlobalScopes()->create([
                                'tenant_id' => $tenantId, 'name' => $e['customer'] ?? $e['phone'], 'phone' => $e['phone'],
                            ]);
                            $customers->push($c);
                            $result['new_customers']++;
                        }
                        $customerId = $c?->id; // over the customer limit: the sale is kept, unlinked
                    }

                    $sale = new Sale([
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'variation_id' => $e['variation_id'],
                        'customer_id' => $customerId,
                        'quantity' => $e['quantity'],
                        'unit_price' => $e['unit_price'],
                        'discount' => $e['discount'],
                        'notes' => $e['notes'],
                        'sale_date' => $e['date'],
                        'payment_method' => $e['payment_method'],
                    ]);
                    $sale->tenant_id = $tenantId;
                    $sale->stock_deducted = $stock === 'deduct';
                    $sale->save();

                    $result['imported']++;
                    $result['total_amount'] += (float) $sale->total_amount;
                });
            } catch (\Throwable $ex) {
                Log::warning('Sales import row failed', ['row' => $e['row'], 'error' => $ex->getMessage()]);
                $this->fail($result, $e['row'], str_starts_with($ex->getMessage(), 'Insufficient') ? 'Not enough stock to deduct' : 'Could not save this row');
            }
        }

        $result['total_amount'] = round($result['total_amount'], 2);

        return $result;
    }

    private function findCustomer(Collection $customers, ?string $name, ?string $phone): ?Customer
    {
        $digits = fn (?string $p) => substr(preg_replace('/\D/', '', (string) $p), -9); // 024… and +23324… match
        if ($phone && strlen($digits($phone)) >= 7) {
            $hit = $customers->first(fn ($c) => $c->phone && $digits($c->phone) === $digits($phone));
            if ($hit) {
                return $hit;
            }
        }

        return $name ? $customers->first(fn ($c) => mb_strtolower(trim($c->name)) === mb_strtolower(trim($name))) : null;
    }

    private function fail(array &$result, int $row, string $message): void
    {
        $result['failed']++;
        if (count($result['errors']) < 50) {
            $result['errors'][] = ['row' => $row, 'message' => $message];
        }
    }
}
