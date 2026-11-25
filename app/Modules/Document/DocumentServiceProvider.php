<?php

namespace App\Modules\Document;

use App\Modules\Document\Listeners\ShrinkStoredImage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class DocumentServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Photos take as little room on the server as they need, whatever page sent them.
        Event::listen(MediaHasBeenAddedEvent::class, ShrinkStoredImage::class);
    }
}
