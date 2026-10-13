<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('stock.csv', $body);
    }

    private function admin(): User
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_headers_are_matched_to_our_fields_by_common_names(): void
    {
        $map = ProductImportService::suggestMapping(['Item Name', 'Qty', 'Selling Price', 'Product Code', 'Notes']);

        $this->assertSame(['name' => 0, 'sku' => 3, 'quantity' => 1, 'unit_price' => 2, 'description' => 4], $map);
    }

    public function test_numbers_cope_with_currency_symbols_and_separators(): void
    {
        $this->assertSame(1200.5, ProductImportService::number('$1,200.50'));
        $this->assertSame(12.5, ProductImportService::number('12,50'));
        $this->assertSame(7.0, ProductImportService::number(7));
        $this->assertNull(ProductImportService::number('n/a'));
    }

    public function test_preview_returns_headers_sample_and_a_suggested_mapping(): void
    {
        $this->admin();

        $this->postJson('/api/v1/products/import/preview', [
            'file' => $this->csv("Item,On hand,Price\nSoap,10,2.5\nTowel,4,9\n"),
        ])->assertOk()
            ->assertJsonPath('headers', ['Item', 'On hand', 'Price'])
            ->assertJsonPath('total_rows', 2)
            ->assertJsonPath('suggested_mapping.name', 0)
            ->assertJsonPath('suggested_mapping.quantity', 1)
            ->assertJsonPath('suggested_mapping.unit_price', 2);
    }

    public function test_import_uses_the_chosen_column_mapping(): void
    {
        $user = $this->admin();

        $this->postJson('/api/v1/products/import', [
            'file' => $this->csv("Thing,Left,Cost to customer,Aisle\nSoap,10,2.5,Bath\nTowel,4,9,Bath\n"),
            'mapping' => json_encode(['name' => 0, 'quantity' => 1, 'unit_price' => 2, 'category' => 3]),
        ])->assertOk()->assertJsonPath('created', 2);

        $soap = Product::where('tenant_id', $user->tenant_id)->where('name', 'Soap')->first();
        $this->assertEquals(10, $soap->quantity);
        $this->assertEquals(2.5, $soap->unit_price);
        $this->assertSame('Bath', $soap->category->name);
    }

    public function test_import_requires_a_name_column(): void
    {
        $this->admin();

        $this->postJson('/api/v1/products/import', [
            'file' => $this->csv("A,B\n1,2\n"),
            'mapping' => json_encode(['quantity' => 0]),
        ])->assertStatus(422);
    }

    public function test_duplicates_by_sku_are_skipped_or_updated_as_chosen(): void
    {
        $user = $this->admin();
        Product::factory()->create(['tenant_id' => $user->tenant_id, 'sku' => 'S-1', 'name' => 'Old', 'quantity' => 1]);
        $file = fn () => $this->csv("name,sku,quantity\nNew,S-1,50\nOther,S-2,5\n");

        $this->postJson('/api/v1/products/import', ['file' => $file()])
            ->assertOk()->assertJsonPath('created', 1)->assertJsonPath('skipped', 1);
        $this->assertSame('Old', Product::where('sku', 'S-1')->first()->name);

        $this->postJson('/api/v1/products/import', ['file' => $file(), 'duplicates' => 'update'])
            ->assertOk()->assertJsonPath('updated', 2);
        $this->assertSame('New', Product::where('sku', 'S-1')->first()->name);
    }

    public function test_bad_rows_are_reported_not_fatal(): void
    {
        $this->admin();

        $this->postJson('/api/v1/products/import', [
            'file' => $this->csv("name,quantity\n,5\nGood,3\nBad,-2\n"),
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('failed', 2)
            ->assertJsonPath('errors.0.row', 2);
    }

    public function test_the_import_is_scoped_to_the_callers_tenant(): void
    {
        $user = $this->admin();
        $this->postJson('/api/v1/products/import', ['file' => $this->csv("name\nMine\n")])->assertOk();

        $this->assertSame($user->tenant_id, Product::withoutGlobalScopes()->where('name', 'Mine')->value('tenant_id'));
    }
}
