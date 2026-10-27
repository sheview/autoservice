<?php

namespace App\Modules\Document\Actions;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/**
 * A small JPEG of a photo for the lists (shortest side SIZE px), made the first time it is asked
 * for and kept in the photo's own folder; removed with the photo (forget, from Media).
 * Null when it cannot be made (no GD, a picture GD cannot open): the photo itself is shown then.
 */
class PhotoThumbnail
{
    public const SIZE = 160;

    private const FILE = '__thumb.jpg';

    public function handle(Media $media): ?string
    {
        $disk = Storage::disk($media->disk);
        $path = $this->path($media);
        if ($disk->exists($path)) {
            return $disk->get($path);
        }
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $stream = $media->stream();
        $image = @imagecreatefromstring((string) stream_get_contents($stream));
        if (is_resource($stream)) {
            fclose($stream);
        }
        if ($image === false) {
            return null;
        }

        $scale = min(1, self::SIZE / min(imagesx($image), imagesy($image)));
        $small = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
        if ($small === false) {
            return null;
        }
        ob_start();
        imagejpeg($small, null, 80);
        $jpeg = (string) ob_get_clean();

        $disk->put($path, $jpeg);

        return $jpeg;
    }

    /** Removes the small copy of a deleted photo, and the photo's folder once it is empty. */
    public function forget(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->delete($this->path($media));
        $folder = PathGeneratorFactory::create($media)->getPath($media);
        if ($disk->exists($folder) && ! $disk->allFiles($folder)) {
            $disk->deleteDirectory($folder);
        }
    }

    private function path(Media $media): string
    {
        return PathGeneratorFactory::create($media)->getPath($media).self::FILE;
    }
}
