<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use App\Modules\Document\Actions\SavePhotoSlot;
use App\Modules\Document\Support\PhotoSlots;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Adds a photo taken on site (a PM visit, Maintenance) to the asset's photos, in its first empty slot:
 * the main photo when there is none yet, else an extra. Photos already there are never replaced, so a full
 * asset refuses it. Unknown ids (other tenant, deleted) give false.
 */
class AddAssetPhoto
{
    public function __construct(private SavePhotoSlot $savePhoto) {}

    public function handle(int $assetId, UploadedFile $file): bool
    {
        $asset = Asset::find($assetId);
        if (! $asset) {
            return false;
        }

        $slot = $this->emptySlot($asset);
        if ($slot === null) {
            throw ValidationException::withMessages(['photo' => __('asset.photos.full', ['max' => PhotoSlots::COUNT])]);
        }

        $this->savePhoto->handle($asset, $slot, $file);

        return true;
    }

    private function emptySlot(Asset $asset): ?int
    {
        foreach (range(0, PhotoSlots::COUNT - 1) as $slot) {
            if (PhotoSlots::find($asset, $slot) === null) {
                return $slot;
            }
        }

        return null;
    }
}
