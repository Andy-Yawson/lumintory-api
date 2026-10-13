<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** One workbook, several sheets: imports let you pick the sheet instead of copying it into a new file. */
class WorkbookSheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create(['plan' => 'custom']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']));
    }

    /** Notes (empty) · Sales · Products, so neither import's sheet is first. */
    private function workbook(): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Notes');
        $sales = $book->createSheet()->setTitle('Sales 2026');
        $sales->fromArray([['Date', 'Item', 'Qty', 'Amount'], ['02/09/2026', 'Soap', 2, 10]]);
        $products = $book->createSheet()->setTitle('Products');
        $products->fromArray([['Name', 'Price', 'Qty'], ['Soap', 5, 40], ['Candle', 20, 6]]);

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'shop.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_preview_lists_every_sheet_and_picks_the_matching_one(): void
    {
        $res = $this->post('/api/v1/products/import/preview', ['file' => $this->workbook()], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(['Notes', 'Sales 2026', 'Products'], array_column($res->json('sheets'), 'name'));
        $this->assertSame([0, 1, 2], array_column($res->json('sheets'), 'rows'));
        $this->assertSame(2, $res->json('sheet'), 'product import picks "Products"');
        $this->assertSame(['Name', 'Price', 'Qty'], $res->json('headers'));

        $this->post('/api/v1/sales/import/preview', ['file' => $this->workbook()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('sheet', 1)->assertJsonPath('headers.1', 'Item');
    }

    public function test_choosing_a_sheet_reads_that_sheet(): void
    {
        $this->post('/api/v1/products/import/preview', ['file' => $this->workbook(), 'sheet' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('sheet', 1)->assertJsonPath('headers.0', 'Date');
    }

    public function test_import_uses_the_chosen_sheet(): void
    {
        $this->post('/api/v1/products/import', [
            'file' => $this->workbook(), 'sheet' => 2, 'mapping' => json_encode(['name' => 0, 'unit_price' => 1, 'quantity' => 2]),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(['Candle', 'Soap'], Product::withoutGlobalScopes()->orderBy('name')->pluck('name')->all());

        $this->post('/api/v1/sales/import', [
            'file' => $this->workbook(), 'sheet' => 1, 'mapping' => json_encode(['date' => 0, 'product' => 1, 'quantity' => 2, 'total' => 3]),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('imported', 1);
        $this->assertEquals(5, Sale::withoutGlobalScopes()->first()->unit_price);
    }
}
