<?php

namespace App\Modules\Document\Http\Concerns;

use App\Modules\Document\Actions\DeletePhotoSlot;
use App\Modules\Document\Actions\SavePhotoSlot;
use App\Modules\Document\Support\PhotoSlots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\HasMedia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The three things a photo controller does with a slot, once it has checked the permission on
 * its own record: upload (replace), show and delete. Photos are on a private disk and are only
 * served through such a controller.
 */
trait ServesPhotoSlots
{
    protected function storePhoto(Request $request, HasMedia $model, int $slot): RedirectResponse
    {
        $request->validate(['photo' => PhotoSlots::FILE_RULES], attributes: ['photo' => __('ui.photos.field')]);

        app(SavePhotoSlot::class)->handle($model, $slot, $request->file('photo'));

        return back()->with('success', __('ui.photos.saved'));
    }

    protected function showPhoto(HasMedia $model, int $slot): StreamedResponse
    {
        $media = PhotoSlots::find($model, $slot);
        abort_if($media === null, 404);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, max-age=86400'],
            'inline',
        );
    }

    protected function destroyPhoto(HasMedia $model, int $slot): RedirectResponse
    {
        app(DeletePhotoSlot::class)->handle($model, $slot);

        return back()->with('success', __('ui.photos.deleted'));
    }
}
