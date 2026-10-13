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

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Sales']));
        $this->product = new Product(['name' => 'Shea Butter', 'unit_price' => 10, 'quantity' => 5]);
        $this->product->tenant_id = $tenant->id;
        $this->product->saveQuietly();
    }

    private function upload(string $name = 'a.jpg')
    {
        return $this->post("/api/v1/products/{$this->product->id}/images", ['image' => UploadedFile::fake()->image($name, 600, 600)], ['Accept' => 'application/json']);
    }

    public function test_upload_stores_the_file_under_the_workspace_and_returns_a_url(): void
    {
        $res = $this->upload()->assertCreated();
        $path = \App\Models\ProductImage::first()->path;

        $this->assertStringStartsWith("tenants/{$this->product->tenant_id}/products/{$this->product->id}/", $path);
        Storage::disk('media')->assertExists($path);
        $this->assertNotEmpty($res->json('image.url'));
        $this->getJson('/api/v1/products')->assertJsonPath('data.0.images.0.id', $res->json('image.id'));
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $this->post("/api/v1/products/{$this->product->id}/images", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/v1/products/{$this->product->id}/images", ['image' => UploadedFile::fake()->image('big.jpg', 600, 600)->size(6000)], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_make_primary_and_delete_renumber_and_remove_the_file(): void
    {
        $first = $this->upload('1.jpg')->json('image');
        $second = $this->upload('2.jpg')->json('image');

        $images = $this->postJson("/api/v1/products/{$this->product->id}/images/{$second['id']}/primary")->assertOk()->json('images');
        $this->assertSame([$second['id'], $first['id']], array_column($images, 'id'));

        $path = \App\Models\ProductImage::find($second['id'])->path;
        $images = $this->deleteJson("/api/v1/products/{$this->product->id}/images/{$second['id']}")->assertOk()->json('images');
        $this->assertSame([$first['id']], array_column($images, 'id'));
        $this->assertSame(0, \App\Models\ProductImage::find($first['id'])->position);
        Storage::disk('media')->assertMissing($path);
    }

    public function test_another_workspace_cannot_touch_the_images(): void
    {
        $image = $this->upload()->json('image');
        $other = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $other->id, 'role' => 'Administrator']));

        $this->post("/api/v1/products/{$this->product->id}/images", ['image' => UploadedFile::fake()->image('x.jpg', 600, 600)], ['Accept' => 'application/json'])->assertStatus(404);
        $this->deleteJson("/api/v1/products/{$this->product->id}/images/{$image['id']}")->assertStatus(404);
    }

    public function test_deleting_the_product_deletes_its_files(): void
    {
        $this->upload();
        $path = \App\Models\ProductImage::first()->path;
        $this->deleteJson("/api/v1/products/{$this->product->id}")->assertNoContent();
        Storage::disk('media')->assertMissing($path);
    }
}
