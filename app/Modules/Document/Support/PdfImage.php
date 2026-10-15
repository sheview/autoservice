<?php

namespace App\Modules\Document\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * An image for a PDF, as a data URL (Gotenberg cannot sign in to fetch it). Photos from phones
 * are shrunk to a sensible width and saved as JPEG, so a report with many photos stays small.
 */
final class PdfImage
{
    public const MAX_WIDTH = 1000;

    public const QUALITY = 72;

    /** Null when the file is missing or is not an image GD can read. */
    public static function fromMedia(Media $media, int $maxWidth = self::MAX_WIDTH): ?string
    {
        try {
            $bytes = stream_get_contents($media->stream());
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? self::fromBytes($bytes, $maxWidth) : null;
    }

    public static function fromBytes(string $bytes, int $maxWidth = self::MAX_WIDTH): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        if ($width > $maxWidth && ($scaled = imagescale($image, $maxWidth)) !== false) {
            imagedestroy($image);
            $image = $scaled;
        }

        ob_start();
        imagejpeg($image, null, self::QUALITY);
        imagedestroy($image);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }
}
