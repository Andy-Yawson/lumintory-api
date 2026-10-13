<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    use RefreshDatabase;

    /** A noisy "photo" (noise compresses badly, like real photos). */
    private function photo(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                imagefilledrectangle($img, $x, $y, $x + 3, $y + 3, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        ob_start();
        imagejpeg($img, null, 92);

        return (string) ob_get_clean();
    }

    public function test_big_photos_are_capped_and_saved_as_jpeg(): void
    {
        $out = ImageOptimizer::optimize($this->photo(4000, 3000), 2000);

        $this->assertSame('jpg', $out['ext']);
        $this->assertSame([2000, 1500], [$out['width'], $out['height']]);
        [$w, $h] = getimagesizefromstring($out['bytes']);
        $this->assertSame([2000, 1500], [$w, $h]);
    }

    public function test_logos_with_transparency_stay_png(): void
    {
        $img = imagecreatetruecolor(1200, 400);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledellipse($img, 600, 200, 300, 300, imagecolorallocate($img, 31, 90, 69));
        ob_start(); imagepng($img); $bytes = (string) ob_get_clean();

        $out = ImageOptimizer::optimize($bytes, 800, 85);
        $this->assertSame('png', $out['ext']);
        $this->assertSame(800, $out['width']);
    }

    public function test_unreadable_files_are_left_alone(): void
    {
        $this->assertNull(ImageOptimizer::optimize('not an image'));
    }

    public function test_product_photo_upload_stores_the_smaller_version(): void
    {
        Storage::fake('media');
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']));
        $p = new Product(['name' => 'Dress', 'quantity' => 1, 'unit_price' => 10]);
        $p->tenant_id = $tenant->id;
        $p->save();

        $original = $this->photo(3000, 2250); // ~a phone photo, under the 15 MB limit
        $this->post("/api/v1/products/{$p->id}/images", ['image' => UploadedFile::fake()->createWithContent('phone.jpg', $original)], ['Accept' => 'application/json'])->assertCreated();

        $stored = Storage::disk('media')->get(ProductImage::firstOrFail()->path);
        $this->assertLessThan(strlen($original), strlen($stored));
        $this->assertSame(2000, getimagesizefromstring($stored)[0]);
        $this->assertStringEndsWith('.jpg', ProductImage::first()->path);
    }
}
