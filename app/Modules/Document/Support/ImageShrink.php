<?php

namespace App\Modules\Document\Support;

use Throwable;

/**
 * Makes a stored photo smaller: the longest side down to MAX_SIDE px, JPEG / WebP at QUALITY, a
 * phone photo turned upright first (its EXIF orientation, as it is not kept). A photo already
 * small is left as it is, and so is any file the result would not make smaller. PNG keeps its
 * format (a screenshot or a signature may be transparent): it is only made smaller in size.
 *
 * The browser makes most photos small before sending (resources/js/lib/image.ts); this is the
 * same for every upload whatever sent it (an older page, an attachment, the API).
 */
class ImageShrink
{
    public const MAX_SIDE = 1600;

    public const QUALITY = 80;

    /** Below this, and not too large on a side, a photo is left alone. */
    public const SMALL_BYTES = 350 * 1024;

    public const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @return int|null the new size in bytes, or null when the file was left as it is
     */
    public function handle(string $path, string $mime): ?int
    {
        if (! in_array($mime, self::TYPES, true) || ! is_file($path) || ! extension_loaded('gd')) {
            return null;
        }
        $before = (int) filesize($path);
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }
        [$width, $height] = $size;
        if ($before <= self::SMALL_BYTES && max($width, $height) <= self::MAX_SIDE) {
            return null;
        }

        try {
            $image = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/webp' => @imagecreatefromwebp($path),
            };
            if ($image === false) {
                return null;
            }
            $image = $this->upright($image, $path, $mime);

            $scale = min(1, self::MAX_SIDE / max(imagesx($image), imagesy($image)));
            if ($scale < 1) {
                $resized = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale), IMG_BICUBIC);
                imagedestroy($image);
                if ($resized === false) {
                    return null;
                }
                $image = $resized;
            }

            $temp = $path.'.shrink';
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $temp, self::QUALITY),
                'image/webp' => imagewebp($image, $temp, self::QUALITY),
                'image/png' => $this->png($image, $temp),
            };
            imagedestroy($image);

            $after = $written ? (int) filesize($temp) : 0;
            if (! $written || $after === 0 || $after >= $before) {
                @unlink($temp);

                return null;
            }
            rename($temp, $path);

            return $after;
        } catch (Throwable $e) {
            report($e);
            @unlink($path.'.shrink');

            return null;
        }
    }

    /** A phone photo stands as it was taken (its EXIF says how to turn it). */
    private function upright(\GdImage $image, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) ((@exif_read_data($path) ?: [])['Orientation'] ?? 1);
        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($turned === null || $turned === false) {
            return $image;
        }
        imagedestroy($image);

        return $turned;
    }

    private function png(\GdImage $image, string $temp): bool
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, $temp, 9);
    }
}
