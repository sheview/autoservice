<?php

namespace App\Modules\Document\Support;

use Closure;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A record's photos as a fixed row of slots: slot 0 is the main photo, the others are extras.
 * Each slot holds at most one photo (the "slot" custom property of the media row); uploading to
 * a slot replaces what is there. Used by the models with the HasPhotoSlots trait.
 */
class PhotoSlots
{
    public const COLLECTION = 'photos';

    public const COUNT = 4;

    public const MAX_KB = 10240;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Validation rule of the uploaded file. */
    public const FILE_RULES = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB];

    public static function find(HasMedia $model, int $slot): ?Media
    {
        return $model->getMedia(self::COLLECTION)
            ->first(fn (Media $media) => (int) $media->getCustomProperty('slot', -1) === $slot);
    }

    /**
     * Every slot, filled or not, for the page.
     *
     * @param  Closure(int): string  $url  the address of a slot (view, upload and delete share it)
     * @return list<array{slot: int, action: string, url: string|null}>
     */
    public static function list(HasMedia $model, Closure $url): array
    {
        $bySlot = $model->getMedia(self::COLLECTION)->keyBy(fn (Media $media) => (int) $media->getCustomProperty('slot', -1));

        return array_map(fn (int $slot) => [
            'slot' => $slot,
            'action' => $url($slot),
            // The media id changes when the photo is replaced, so the browser does not show the old one.
            'url' => isset($bySlot[$slot]) ? $url($slot).'?v='.$bySlot[$slot]->id : null,
        ], range(0, self::COUNT - 1));
    }
}
