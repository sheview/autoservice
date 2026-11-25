<?php

namespace App\Modules\Document\Listeners;

use App\Modules\Document\Support\ImageShrink;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Throwable;

/**
 * Every photo stored by any module (repair photos, PM photos, attachments, logos ...) is made
 * smaller as soon as it is on the disk, before its thumbnails are made (ImageShrink).
 */
class ShrinkStoredImage
{
    public function __construct(private ImageShrink $shrink) {}

    public function handle(MediaHasBeenAddedEvent $event): void
    {
        $media = $event->media;

        try {
            $path = $media->getPath(); // a local disk; another kind is left as it is
        } catch (Throwable) {
            return;
        }

        $size = $this->shrink->handle($path, (string) $media->mime_type);
        if ($size !== null) {
            $media->forceFill(['size' => $size])->saveQuietly();
        }
    }
}
