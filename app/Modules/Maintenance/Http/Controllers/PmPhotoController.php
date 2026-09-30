<?php

namespace App\Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Actions\AddPmPhoto;
use App\Modules\Maintenance\Actions\DeletePmPhoto;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site photos of an asset in a PM round. They are on a private disk and only served here,
 * after the permission check on the round.
 */
class PmPhotoController extends Controller
{
    public const MAX_KB = 10240;

    public function store(Request $request, PmVisit $visit, PmVisitItem $item, AddPmPhoto $addPhoto): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $request->validate(
            ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB]],
            attributes: ['photo' => __('maintenance.fields.photo')],
        );

        $addPhoto->handle($item, $request->file('photo'));

        return back()->with('success', __('maintenance.photos.added'));
    }

    public function show(PmVisit $visit, PmVisitItem $item, int $photo): StreamedResponse
    {
        Gate::authorize('view', $visit);

        $media = $this->photo($item, $photo);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, max-age=86400'],
            'inline',
        );
    }

    public function destroy(PmVisit $visit, PmVisitItem $item, int $photo, DeletePmPhoto $deletePhoto): RedirectResponse
    {
        Gate::authorize('perform', $visit);

        $deletePhoto->handle($item, $this->photo($item, $photo));

        return back()->with('success', __('maintenance.photos.deleted'));
    }

    private function photo(PmVisitItem $item, int $id): Media
    {
        $media = $item->getMedia(PmVisitItem::PHOTOS)->firstWhere('id', $id);
        abort_if($media === null, 404);

        return $media;
    }
}
