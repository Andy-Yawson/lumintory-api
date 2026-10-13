<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Product photos in object storage (R2). Position 0 is the primary image. */
class ProductImageController extends Controller
{
    public const MAX_IMAGES = 8;

    public function store(Request $request, Product $product)
    {
        $this->authorizeProduct($product);
        $request->validate([
            // Up to 15 MB in: every photo is shrunk (max 2000px JPEG, metadata stripped) before storage.
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:15360', 'dimensions:min_width=100,min_height=100,max_width=12000,max_height=12000'],
        ]);

        if ($product->images()->count() >= self::MAX_IMAGES) {
            return response()->json(['message' => 'A product can have up to '.self::MAX_IMAGES.' photos.'], 422);
        }

        $file = $request->file('image');
        $optimized = \App\Services\ImageOptimizer::optimize($file->getContent(), 2000);
        $path = "tenants/{$product->tenant_id}/products/{$product->id}/".Str::uuid().'.'.($optimized['ext'] ?? $file->extension());

        try {
            Storage::disk('media')->put($path, $optimized['bytes'] ?? $file->getContent(), ['ContentType' => $optimized['mime'] ?? $file->getMimeType()]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Photo storage is unavailable right now. Please try again shortly.'], 503);
        }

        $image = ProductImage::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'path' => $path,
            'position' => (int) $product->images()->max('position') + ($product->images()->exists() ? 1 : 0),
        ]);

        return response()->json(['image' => $image, 'images' => $product->images()->get()], 201);
    }

    public function destroy(Product $product, ProductImage $image)
    {
        $this->authorizeProduct($product);
        abort_unless($image->product_id === $product->id, 404);

        $path = $image->path;
        $image->delete();
        $this->renumber($product);
        rescue(fn () => Storage::disk('media')->delete($path), report: true);

        return response()->json(['images' => $product->images()->get()]);
    }

    /** Make this image the primary (first) one. */
    public function primary(Product $product, ProductImage $image)
    {
        $this->authorizeProduct($product);
        abort_unless($image->product_id === $product->id, 404);

        DB::transaction(function () use ($product, $image) {
            $product->images()->where('id', '!=', $image->id)->increment('position');
            $image->update(['position' => 0]);
            $this->renumber($product);
        });

        return response()->json(['images' => $product->images()->get()]);
    }

    private function renumber(Product $product): void
    {
        foreach ($product->images()->get() as $i => $img) {
            if ($img->position !== $i) {
                $img->update(['position' => $i]);
            }
        }
    }

    private function authorizeProduct(Product $product): void
    {
        abort_unless($product->tenant_id === Auth::user()->tenant_id, 403);
    }
}
