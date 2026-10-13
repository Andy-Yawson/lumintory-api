<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductVideo;
use App\Models\Product;
use App\Models\ProductVideo;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $tenant = Tenant::factory()->create(['plan' => 'pro']);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'Administrator']));
        $this->product = new Product(['name' => 'Dress', 'quantity' => 2, 'unit_price' => 100]);
        $this->product->tenant_id = $tenant->id;
        $this->product->save();
    }

    /** Sends $bytes through start → chunk(s) → complete, like the browser does. */
    private function upload(string $bytes, int $chunk = 4): int
    {
        $p = $this->product->id;
        $id = $this->postJson("/api/v1/products/{$p}/videos", ['name' => 'clip.mov', 'size' => strlen($bytes), 'type' => 'video/quicktime'])
            ->assertCreated()->json('video.id');
        foreach (str_split($bytes, $chunk) as $i => $part) {
            $this->post("/api/v1/products/{$p}/videos/{$id}/chunk", ['index' => $i, 'chunk' => UploadedFile::fake()->createWithContent('c', $part)], ['Accept' => 'application/json'])->assertOk();
        }
        $this->postJson("/api/v1/products/{$p}/videos/{$id}/complete")->assertOk()->assertJsonPath('video.status', 'processing');

        return $id;
    }

    public function test_chunks_are_assembled_stored_and_queued(): void
    {
        Queue::fake();
        $id = $this->upload('0123456789abcdef');

        $video = ProductVideo::find($id);
        $this->assertSame('0123456789abcdef', Storage::disk('media')->get($video->source_path));
        $this->assertFileDoesNotExist($video->partPath());
        Queue::assertPushed(ProcessProductVideo::class, fn ($job) => $job->videoId === $id);
    }

    public function test_out_of_order_and_repeated_chunks(): void
    {
        $p = $this->product->id;
        $id = $this->postJson("/api/v1/products/{$p}/videos", ['size' => 8, 'type' => 'video/mp4'])->json('video.id');
        $send = fn ($i, $c) => $this->post("/api/v1/products/{$p}/videos/{$id}/chunk", ['index' => $i, 'chunk' => UploadedFile::fake()->createWithContent('c', $c)], ['Accept' => 'application/json']);

        $send(1, 'efgh')->assertStatus(422);
        $send(0, 'abcd')->assertOk();
        $send(0, 'abcd')->assertOk()->assertJsonPath('received', 1); // retry: not appended twice
        $this->postJson("/api/v1/products/{$p}/videos/{$id}/complete")->assertStatus(422); // incomplete
    }

    public function test_limits_type_size_and_count(): void
    {
        $p = $this->product->id;
        $this->postJson("/api/v1/products/{$p}/videos", ['size' => 10, 'type' => 'image/png'])->assertStatus(422);
        $this->postJson("/api/v1/products/{$p}/videos", ['size' => ProductVideo::MAX_UPLOAD_BYTES + 1, 'type' => 'video/mp4'])->assertStatus(422);
        $this->postJson("/api/v1/products/{$p}/videos", ['size' => 10, 'type' => 'video/mp4'])->assertCreated();
        $this->postJson("/api/v1/products/{$p}/videos", ['size' => 10, 'type' => 'video/mp4'])->assertCreated();
        $this->postJson("/api/v1/products/{$p}/videos", ['size' => 10, 'type' => 'video/mp4'])->assertStatus(422);
    }

    public function test_another_workspace_cannot_touch_the_video(): void
    {
        $id = $this->postJson("/api/v1/products/{$this->product->id}/videos", ['size' => 10, 'type' => 'video/mp4'])->json('video.id');
        Sanctum::actingAs(User::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'role' => 'Administrator']));
        $this->deleteJson("/api/v1/products/{$this->product->id}/videos/{$id}")->assertNotFound();
    }

    public function test_conversion_makes_an_mp4_and_poster_and_trims_long_clips(): void
    {
        // Needs real ffmpeg + ffprobe (some Mac installs ship ffmpeg renamed as ffprobe).
        if (! str_starts_with((string) shell_exec('ffprobe -version 2>/dev/null'), 'ffprobe version')) {
            $this->markTestSkipped('ffmpeg/ffprobe not installed');
        }
        foreach ([3 => false, 35 => true] as $seconds => $trimmed) {
            $file = tempnam(sys_get_temp_dir(), 'vid').'.mov';
            shell_exec("ffmpeg -y -v error -f lavfi -i testsrc=size=480x854:rate=15 -f lavfi -i sine=frequency=440 -t {$seconds} -c:v libx264 -preset ultrafast -c:a aac -shortest ".escapeshellarg($file));
            Queue::fake();
            $id = $this->upload(file_get_contents($file), 512 * 1024);
            @unlink($file);

            (new ProcessProductVideo($id))->handle();
            $video = ProductVideo::find($id);

            $this->assertSame('ready', $video->status, (string) $video->error);
            $this->assertSame($trimmed, $video->trimmed);
            $this->assertEqualsWithDelta(min($seconds, 30), $video->duration, 0.6);
            $this->assertSame([480, 854], [$video->width, $video->height], 'portrait stays portrait');
            Storage::disk('media')->assertExists($video->path);
            Storage::disk('media')->assertExists($video->poster_path);
            $this->assertNull($video->source_path, 'original removed after conversion');

            $this->deleteJson("/api/v1/products/{$this->product->id}/videos/{$id}")->assertOk();
            Storage::disk('media')->assertMissing($video->path);
        }
    }

    public function test_a_file_that_is_not_a_video_fails_with_a_clear_message(): void
    {
        Queue::fake();
        $id = $this->upload(str_repeat('not a video ', 100), 600);
        (new ProcessProductVideo($id))->handle();
        $video = ProductVideo::find($id);
        $this->assertSame('failed', $video->status);
        $this->assertStringContainsString("doesn't look like a video", $video->error);
    }
}
