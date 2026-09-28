<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Removes a file from a contract (the file is deleted from storage too).
 */
class DeleteContractDocument
{
    public function handle(Contract $contract, Media $media): void
    {
        $media->delete();

        activity()->performedOn($contract)->event('document_deleted')
            ->withProperties(['file' => $media->file_name])->log('ลบไฟล์สัญญา');
    }
}
