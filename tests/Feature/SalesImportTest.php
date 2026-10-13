<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']));
    }

    private function product(string $name, float $qty = 10, float $price = 20, ?string $sku = null): Product
    {
        $p = new Product(['name' => $name, 'quantity' => $qty, 'unit_price' => $price, 'sku' => $sku]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        return $p;
    }

    private function csv(array $rows): UploadedFile
    {
        $body = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? "\"{$v}\"" : $v, $r)), $rows));

        return UploadedFile::fake()->createWithContent('sales.csv', $body);
    }

    private function send(string $url, array $rows, array $mapping, array $extra = [])
    {
        return $this->post("/api/v1/sales/import{$url}", ['file' => $this->csv($rows), 'mapping' => json_encode($mapping)] + $extra, ['Accept' => 'application/json']);
    }

    private const HEAD = ['Date', 'Item', 'Qty', 'Amount', 'Paid by', 'Customer', 'Phone'];
    private const MAP = ['date' => 0, 'product' => 1, 'quantity' => 2, 'total' => 3, 'payment_method' => 4, 'customer' => 5, 'phone' => 6];

    public function test_headers_are_matched_automatically(): void
    {
        $this->assertSame(self::MAP, SalesImportService::suggestMapping(self::HEAD));
    }

    public function test_history_is_imported_without_touching_stock(): void
    {
        $soap = $this->product('Soap', 10, 5);

        $this->send('', [self::HEAD, ['02/09/2026', 'soap', '3', '15', 'momo', 'Ama', '024 123 4567']], self::MAP)
            ->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('new_customers', 1);

        $sale = Sale::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2026-09-02', $sale->sale_date->toDateString(), 'day-first date');
        $this->assertEquals(5, $sale->unit_price, 'unit price worked out from the line total');
        $this->assertSame('Mobile Money', $sale->payment_method);
        $this->assertFalse($sale->stock_deducted);
        $this->assertEquals(10, $soap->fresh()->quantity, 'stock untouched');

        $sale->delete();
        $this->assertEquals(10, $soap->fresh()->quantity, 'deleting imported history gives no stock back');
    }

    public function test_deduct_mode_uses_the_normal_stock_rules(): void
    {
        $soap = $this->product('Soap', 4, 5);

        $this->send('/dry-run', [self::HEAD, ['', 'Soap', '3', '15', '', '', ''], ['', 'Soap', '3', '15', '', '', '']], self::MAP, ['stock' => 'deduct'])
            ->assertOk()->assertJsonPath('summary.import', 1)->assertJsonPath('summary.error', 1);

        $this->send('', [self::HEAD, ['', 'Soap', '3', '15', '', '', '']], self::MAP, ['stock' => 'deduct'])->assertOk();
        $this->assertEquals(1, $soap->fresh()->quantity);
        $this->assertTrue(Sale::withoutGlobalScopes()->first()->stock_deducted);
    }

    public function test_unknown_products_are_skipped_or_created_with_no_stock(): void
    {
        $rows = [self::HEAD, ['', 'Kente Bag', '2', '300', 'cash', '', '']];

        $this->send('/dry-run', $rows, self::MAP)->assertJsonPath('summary.error', 1)->assertJsonPath('rows.0.reason', 'No product called "Kente Bag"');

        $this->send('', $rows, self::MAP, ['missing' => 'create'])->assertOk()->assertJsonPath('new_products', 1);
        $bag = Product::withoutGlobalScopes()->where('name', 'Kente Bag')->firstOrFail();
        $this->assertEquals(0, $bag->quantity);
        $this->assertEquals(150, $bag->unit_price);
    }

    public function test_variations_skus_and_existing_customers_are_matched(): void
    {
        $shirt = $this->product('Shirt', 10, 50, 'SH-1');
        $red = ProductVariation::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'product_id' => $shirt->id, 'name' => 'Red', 'quantity' => 5, 'unit_price' => 55]);
        $ama = Customer::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ama Mensah', 'phone' => '+233241234567']);

        $this->send('', [['SKU', 'Variant', 'Qty', 'Phone'], ['sh-1', 'red', '1', '0241234567']], ['product' => 0, 'variation' => 1, 'quantity' => 2, 'phone' => 3])
            ->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('new_customers', 0);

        $sale = Sale::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($red->id, $sale->variation_id);
        $this->assertEquals(55, $sale->unit_price, 'falls back to the variation price');
        $this->assertSame($ama->id, $sale->customer_id);
    }

    public function test_bad_rows_are_reported_and_nothing_is_saved_for_them(): void
    {
        $this->product('Soap');
        $future = now()->addDays(3)->format('d/m/Y');

        $res = $this->send('', [self::HEAD,
            ['', 'Soap', '0', '10', '', '', ''],
            ['31/02/2026', 'Soap', '1', '10', '', '', ''],
            [$future, 'Soap', '1', '10', '', '', ''],
        ], self::MAP)->assertOk();

        $this->assertSame(0, $res->json('imported'));
        $this->assertSame(['Quantity must be more than 0', 'Could not read the date "31/02/2026"', 'Date is in the future'], array_column($res->json('errors'), 'message'));
        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_only_administrators_can_import(): void
    {
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']));
        $this->send('/preview', [self::HEAD], [])->assertForbidden();
    }

    public function test_month_first_files_are_detected(): void
    {
        $this->assertFalse(SalesImportService::detectDayFirst([['10/25/2026'], ['10/02/2026']], 0));
        $this->assertTrue(SalesImportService::detectDayFirst([['05/06/2026']], 0), 'ambiguous → day first');
    }
}
