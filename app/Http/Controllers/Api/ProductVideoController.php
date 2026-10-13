<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProductVideo;
use App\Models\Product;
use App\Models\ProductVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Short product videos. Phone videos are big, so the browser sends them in 8 MB chunks:
 *   start → chunk × n → complete (assembled file goes to R2, conversion is queued) → poll until ready.
 */
class ProductVideoController extends Controller
{
    private function authorizeProduct(Product $product): void
    {
        abort_unless($product->tenant_id === Auth::user()->tenant_id, 404);
    }

    private function own(Product $product, ProductVideo $video): void
    {
        $this->authorizeProduct($product);
        abort_unless($video->product_id === $product->id, 404);
    }

    public function index(Product $product)
    {
        $this->authorizeProduct($product);

        return response()->json(['videos' => $product->videos()->get()]);
    }

    public function start(Request $request, Product $product)
    {
        $this->authorizeProduct($product);
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'size' => 'required|integer|min:1|max:'.ProductVideo::MAX_UPLOAD_BYTES,
            'type' => ['required', 'string', 'regex:#^video/#'],
        ], ['size.max' => 'Videos can be up to 200 MB. Keep it short: we use the first '.ProductVideo::MAX_SECONDS.' seconds.']);

        // Abandoned uploads don't count against the limit for long.
        $product->videos()->where('status', 'uploading')->where('updated_at', '<', now()->subHour())->get()->each(function ($v) { $v->deleteFiles(); $v->delete(); });
        if ($product->videos()->where('status', '!=', 'failed')->count() >= ProductVideo::MAX_PER_PRODUCT) {
            return response()->json(['message' => 'A product can have up to '.ProductVideo::MAX_PER_PRODUCT.' videos. Remove one first.'], 422);
        }

        $video = ProductVideo::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'original_name' => $data['name'] ?? null,
            'size' => $data['size'],
        ]);
        @mkdir(dirname($video->partPath()), 0775, true);
        file_put_contents($video->partPath(), '');

        return response()->json([
            'video' => $video,
            'chunk_size' => ProductVideo::CHUNK_BYTES,
            'chunks' => (int) ceil($data['size'] / ProductVideo::CHUNK_BYTES),
        ], 201);
    }

    public function chunk(Request $request, Product $product, ProductVideo $video)
    {
        $this->own($product, $video);
        $data = $request->validate([
            'index' => 'required|integer|min:0',
            'chunk' => 'required|file|max:'.(int) (ProductVideo::CHUNK_BYTES / 1024 + 64),
        ]);
        abort_unless($video->status === 'uploading', 422, 'This upload is already finished.');

        // Chunks arrive in order; a repeated chunk (retry after a timeout) is acknowledged, not appended twice.
        if ($data['index'] < $video->chunks_received) {
            return response()->json(['received' => $video->chunks_received]);
        }
        abort_unless($data['index'] === $video->chunks_received, 422, 'Chunks arrived out of order. Please try the upload again.');

        $written = file_put_contents($video->partPath(), $request->file('chunk')->getContent(), FILE_APPEND);
        abort_if($written === false, 500, 'Could not save the upload.');
        abort_if(filesize($video->partPath()) > $video->size, 422, 'The upload is larger than expected.');

        $video->increment('chunks_received');

        return response()->json(['received' => $video->chunks_received]);
    }

    public function complete(Product $product, ProductVideo $video)
    {
        $this->own($product, $video);
        abort_unless($video->status === 'uploading', 422, 'This upload is already finished.');
        clearstatcache();
        if (! is_file($video->partPath()) || filesize($video->partPath()) !== (int) $video->size) {
            return response()->json(['message' => 'The upload is incomplete. Please try again.'], 422);
        }

        $source = "tenants/{$video->tenant_id}/products/{$product->id}/videos/source-".Str::uuid();
        try {
            $stream = fopen($video->partPath(), 'r');
            Storage::disk('media')->writeStream($source, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Video storage is unavailable right now. Please try again shortly.'], 503);
        }
        @unlink($video->partPath());

        $video->update(['status' => 'processing', 'source_path' => $source]);
        ProcessProductVideo::dispatch($video->id);

        return response()->json(['video' => $video->fresh()]);
    }

    public function show(Product $product, ProductVideo $video)
    {
        $this->own($product, $video);

        return response()->json(['video' => $video]);
    }

    public function destroy(Product $product, ProductVideo $video)
    {
        $this->own($product, $video);
        $video->deleteFiles();
        $video->delete();

        return response()->json(['videos' => $product->videos()->get()]);
    }
}
