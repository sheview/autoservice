<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Asset\Actions\AddAssetPhoto;
use App\Modules\Maintenance\Models\PmVisitItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Attaches a site photo to a piece of equipment while the work is going on (at most MAX_PHOTOS
 * each), optionally of one checklist question (its key, kept as the "question" property) and
 * with a caption; both are printed with the photo in the report.
 *
 * The technician chooses where the photo goes (TARGETS): the PM report, the asset's own photos
 * (Asset, its first empty slot) or both. Every check runs before anything is saved, so a refused
 * photo is saved nowhere.
 */
class AddPmPhoto
{
    public const TARGET_REPORT = 'report';

    public const TARGET_ASSET = 'asset';

    public const TARGET_BOTH = 'both';

    public const TARGETS = [self::TARGET_REPORT, self::TARGET_ASSET, self::TARGET_BOTH];

    public function __construct(private AddAssetPhoto $addAssetPhoto) {}

    public function handle(PmVisitItem $item, UploadedFile $file, ?string $question = null, ?string $caption = null, string $target = self::TARGET_REPORT): void
    {
        $toReport = $target !== self::TARGET_ASSET;

        if (! $item->visit->isWorking()) {
            throw ValidationException::withMessages(['photo' => __('maintenance.visits.not_in_progress')]);
        }
        if ($toReport && $question !== null && ! in_array($question, array_column($item->checklist ?? [], 'key'), true)) {
            throw ValidationException::withMessages(['photo' => __('maintenance.photos.unknown_question')]);
        }
        if ($toReport && $item->getMedia(PmVisitItem::PHOTOS)->count() >= PmVisitItem::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photo' => __('maintenance.photos.too_many', ['max' => PmVisitItem::MAX_PHOTOS])]);
        }

        if ($target !== self::TARGET_REPORT) {
            // Saving a photo moves its upload away, so the asset gets its own copy when the report needs the file too.
            $saved = $this->addAssetPhoto->handle($item->asset_id, $toReport ? $this->copyOf($file) : $file);
            if (! $saved) {
                throw ValidationException::withMessages(['photo' => __('maintenance.visits.asset_not_found')]);
            }
        }

        if ($toReport) {
            $item->addMedia($file)
                ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->withCustomProperties(array_filter(['question' => $question, 'caption' => filled($caption) ? trim($caption) : null]))
                ->toMediaCollection(PmVisitItem::PHOTOS);
        }
    }

    private function copyOf(UploadedFile $file): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pmphoto');
        copy($file->getRealPath(), $path);

        return new UploadedFile($path, $file->getClientOriginalName(), $file->getClientMimeType(), null, true);
    }
}
