<?php

namespace App\Services;

/**
 * Shrinks uploaded images before they go to storage: turns phone photos the right way up,
 * caps the longest side, strips metadata (EXIF can include the GPS location where a photo was
 * taken) and re-encodes: JPEG for photos, PNG when the image has transparency (logos).
 * Not WebP: link-preview generators, catalogue feeds (Meta) and the receipt PDF don't all read it.
 * A 5 MB phone photo usually ends up around 300–600 KB with no visible difference on a product page.
 */
class ImageOptimizer
{
    /**
     * @return array{bytes: string, ext: string, mime: string, width: int, height: int}|null  null if GD can't read it
     */
    public static function optimize(string $bytes, int $maxSide = 2000, int $quality = 82): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! ($img = @imagecreatefromstring($bytes))) {
            return null;
        }

        $img = self::orient($img, $bytes);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $scale = min(1, $maxSide / max($w, $h));
        if ($scale < 1) {
            [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
            $resized = imagecreatetruecolor($nw, $nh);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            [$img, $w, $h] = [$resized, $nw, $nh];
        } else {
            if (! imageistruecolor($img)) {
                imagepalettetotruecolor($img); // GIF/8-bit PNG → WebP needs truecolor
            }
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $png = self::hasTransparency($img);
        ob_start();
        if ($png) {
            $ok = imagepng($img, null, 9);
        } else {
            imageinterlace($img, true); // progressive JPEG: shows a preview while loading
            $ok = imagejpeg($img, null, $quality);
        }
        $out = (string) ob_get_clean();
        imagedestroy($img);
        if (! $ok || $out === '') {
            return null;
        }

        // Already-small, well-compressed files can come out bigger; keep the original then.
        if (strlen($out) >= strlen($bytes) && $scale >= 1 && ! self::hasExif($bytes)) {
            return null;
        }

        return ['bytes' => $out, 'ext' => $png ? 'png' : 'jpg', 'mime' => $png ? 'image/png' : 'image/jpeg', 'width' => $w, 'height' => $h];
    }

    /** Phones store "rotate me" in EXIF instead of rotating pixels; bake it in before EXIF is dropped. */
    private static function orient(\GdImage $img, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! self::hasExif($bytes)) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }

    /** Samples a grid of pixels: enough to tell a logo on a transparent background from a photo. */
    private static function hasTransparency(\GdImage $img): bool
    {
        [$w, $h] = [imagesx($img), imagesy($img)];
        $step = max(1, (int) (min($w, $h) / 60));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function hasExif(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8") && str_contains(substr($bytes, 0, 65536), 'Exif');
    }
}
