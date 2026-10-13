<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = ['tenant_id', 'product_id', 'path', 'position'];

    protected $hidden = ['path', 'tenant_id'];

    protected $appends = ['url'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Public URL when the bucket has a public domain (MEDIA_PUBLIC_URL, e.g. for a storefront),
     * otherwise a signed link that expires after a day.
     */
    public function getUrlAttribute(): ?string
    {
        $disk = Storage::disk('media');

        try {
            return config('filesystems.disks.media.url')
                ? $disk->url($this->path)
                : $disk->temporaryUrl($this->path, now()->addDay());
        } catch (\Throwable) {
            return null;
        }
    }
}
