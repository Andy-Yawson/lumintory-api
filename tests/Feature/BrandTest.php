<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->tenant = Tenant::factory()->create(['plan' => 'custom', 'settings' => ['currency' => 'GHS']]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Administrator']);
        Sanctum::actingAs($this->admin);
    }

    public function test_an_admin_sets_a_colour_and_it_comes_back_on_me(): void
    {
        $this->postJson('/api/v1/tenant/brand', ['color' => '#7A1F5C'])->assertOk()->assertJsonPath('color', '#7a1f5c');

        $this->getJson('/api/v1/me')->assertJsonPath('tenant.brand.color', '#7a1f5c');
        $this->assertSame('GHS', $this->tenant->fresh()->settings['currency'], 'other settings untouched');
    }

    public function test_colours_too_light_for_white_text_are_refused(): void
    {
        $this->postJson('/api/v1/tenant/brand', ['color' => '#f5d76e'])->assertStatus(422)->assertJsonValidationErrors('color');
        $this->postJson('/api/v1/tenant/brand', ['color' => 'red'])->assertStatus(422);
    }

    public function test_clearing_the_colour_falls_back_to_zinnvy(): void
    {
        $this->postJson('/api/v1/tenant/brand', ['color' => '#123456']);
        $this->postJson('/api/v1/tenant/brand', ['color' => null])->assertOk()->assertJsonPath('color', null);
    }

    public function test_logo_upload_replaces_and_removes_the_old_file(): void
    {
        $this->post('/api/v1/tenant/brand/logo', ['logo' => UploadedFile::fake()->image('a.png', 200, 80)], ['Accept' => 'application/json'])->assertOk();
        $first = $this->tenant->fresh()->settings['logo_path'];
        Storage::disk('media')->assertExists($first);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/brand/", $first);

        $this->post('/api/v1/tenant/brand/logo', ['logo' => UploadedFile::fake()->image('b.jpg', 200, 80)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('media')->assertMissing($first);

        $this->deleteJson('/api/v1/tenant/brand/logo')->assertOk()->assertJsonPath('logo_url', null);
        $this->assertArrayNotHasKey('logo_path', $this->tenant->fresh()->settings);
    }

    public function test_svg_and_large_files_are_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/api/v1/tenant/brand/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/tenant/brand/logo', ['logo' => UploadedFile::fake()->image('big.png')->size(11000)], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_sales_users_cannot_change_the_brand(): void
    {
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'Sales']));
        $this->postJson('/api/v1/tenant/brand', ['color' => '#123456'])->assertForbidden();
        $this->getJson('/api/v1/tenant/brand')->assertOk();
    }

    public function test_the_receipt_renders_with_the_brand_colour_and_logo(): void
    {
        $this->postJson('/api/v1/tenant/brand', ['color' => '#7a1f5c']);
        $this->post('/api/v1/tenant/brand/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 80)], ['Accept' => 'application/json']);
        $p = new Product(['name' => 'Dress', 'quantity' => 3, 'unit_price' => 250]);
        $p->tenant_id = $this->tenant->id;
        $p->save();

        $id = $this->postJson('/api/v1/sales', ['items' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 250]], 'payment_method' => 'Cash'])
            ->assertCreated()->json('sales.0.id');

        $this->get("/api/v1/sales/{$id}/receipt")->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
