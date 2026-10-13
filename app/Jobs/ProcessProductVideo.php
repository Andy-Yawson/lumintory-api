<?php

namespace App\Jobs;

use App\Models\ProductVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Turns whatever a phone recorded (MOV/HEVC, huge bitrates, any orientation) into a small
 * MP4 that plays in every browser: H.264/AAC, at most 720px wide (portrait stays portrait),
 * first 30 seconds, "faststart" so it begins playing before it's fully downloaded. Plus a poster frame.
 */
// NotTenantAware: the job finds its workspace from the video row; without it the multitenancy
// package silently drops the job ("current tenant could not be determined").
class ProcessProductVideo implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = ProductVideo::find($this->videoId);
        if (! $video || $video->status !== 'processing' || ! $video->source_path) {
            return;
        }

        $dir = sys_get_temp_dir().'/zinnvy-video-'.$video->id.'-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $in = "{$dir}/in";
        $out = "{$dir}/out.mp4";
        $poster = "{$dir}/poster.jpg";

        try {
            $disk = Storage::disk('media');
            $read = $disk->readStream($video->source_path);
            $write = fopen($in, 'w');
            stream_copy_to_stream($read, $write);
            fclose($write);
            if (is_resource($read)) {
                fclose($read);
            }

            $probe = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'format=duration:stream=width,height', '-of', 'json', $in]);
            $meta = json_decode($probe->output(), true);
            $duration = (float) ($meta['format']['duration'] ?? 0);
            if (! $probe->successful() || empty($meta['streams']) || $duration <= 0) {
                throw new \RuntimeException('This file doesn\'t look like a video we can read. Try an MP4 or MOV from your phone.');
            }

            $max = ProductVideo::MAX_SECONDS;
            $convert = Process::timeout(150)->run([
                'ffmpeg', '-y', '-v', 'error', '-i', $in, '-t', (string) $max,
                '-vf', "scale='min(720,iw)':-2", '-r', '30',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '27', '-pix_fmt', 'yuv420p', '-profile:v', 'main',
                '-c:a', 'aac', '-b:a', '96k', '-ac', '2',
                '-movflags', '+faststart', $out,
            ]);
            if (! $convert->successful() || ! is_file($out)) {
                Log::warning('Video conversion failed', ['video' => $video->id, 'error' => mb_substr($convert->errorOutput(), 0, 500)]);
                throw new \RuntimeException('We couldn\'t convert this video. Try a different file.');
            }

            $at = min(1, max(0, $duration / 3));
            Process::timeout(30)->run(['ffmpeg', '-y', '-v', 'error', '-ss', (string) $at, '-i', $out, '-frames:v', '1', '-vf', "scale='min(720,iw)':-2", '-q:v', '4', $poster]);

            $outMeta = json_decode(Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'json', $out])->output(), true);

            $base = "tenants/{$video->tenant_id}/products/{$video->product_id}/videos/".pathinfo($video->source_path, PATHINFO_FILENAME);
            $base = str_replace('/source-', '/', $base);
            $disk->put("{$base}.mp4", fopen($out, 'r'), ['ContentType' => 'video/mp4']);
            if (is_file($poster)) {
                $disk->put("{$base}.jpg", fopen($poster, 'r'), ['ContentType' => 'image/jpeg']);
            }
            rescue(fn () => $disk->delete($video->source_path), report: false);

            $video->update([
                'status' => 'ready',
                'path' => "{$base}.mp4",
                'poster_path' => is_file($poster) ? "{$base}.jpg" : null,
                'source_path' => null,
                'duration' => round(min($duration, $max), 2),
                'trimmed' => $duration > $max + 0.5,
                'width' => $outMeta['streams'][0]['width'] ?? null,
                'height' => $outMeta['streams'][0]['height'] ?? null,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->fail($video, $e instanceof \RuntimeException ? $e->getMessage() : 'We couldn\'t process this video. Please try again.');
            if (! $e instanceof \RuntimeException) {
                report($e);
            }
        } finally {
            foreach ([$in, $out, $poster] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function fail(ProductVideo $video, string $message): void
    {
        if ($video->source_path) {
            rescue(fn () => Storage::disk('media')->delete($video->source_path), report: false);
        }
        $video->update(['status' => 'failed', 'error' => $message, 'source_path' => null]);
    }

    public function failed(\Throwable $e): void
    {
        // Killed by the timeout or the worker: don't leave it "processing" forever.
        if ($video = ProductVideo::find($this->videoId)) {
            $this->fail($video, 'Processing took too long. Try a shorter or smaller video.');
        }
    }
}
