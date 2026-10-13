<?php

namespace App\Models;

use App\Services\Shop\ShopCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductVideo extends Model
{
    /** Longest clip we keep; longer uploads are trimmed to the first MAX_SECONDS. */
    public const MAX_SECONDS = 30;
    public const MAX_PER_PRODUCT = 2;
    public const MAX_UPLOAD_BYTES = 200 * 1024 * 1024;
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    protected $guarded = ['id'];

    protected $hidden = ['tenant_id', 'source_path', 'path', 'poster_path'];

    protected $appends = ['url', 'poster_url'];

    protected $casts = ['duration' => 'float', 'trimmed' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => ShopCache::bust());
        static::deleted(fn () => ShopCache::bust());
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? self::mediaUrl($this->path) : null;
    }

    public function getPosterUrlAttribute(): ?string
    {
        return $this->poster_path ? self::mediaUrl($this->poster_path) : null;
    }

    private static function mediaUrl(string $path): ?string
    {
        $disk = Storage::disk('media');

        return rescue(fn () => config('filesystems.disks.media.url') ? $disk->url($path) : $disk->temporaryUrl($path, now()->addDay()), null, report: false);
    }

    /** Local staging file for chunks while the upload is in progress. */
    public function partPath(): string
    {
        return storage_path("app/video-uploads/{$this->id}.part");
    }

    public function deleteFiles(): void
    {
        $paths = array_filter([$this->source_path, $this->path, $this->poster_path]);
        if ($paths) {
            rescue(fn () => Storage::disk('media')->delete($paths), report: true);
        }
        @unlink($this->partPath());
    }
}
