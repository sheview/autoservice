<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Contract\Models\Contract;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches a file (signed contract, appendix, ...) to a contract.
 */
class AddContractDocument
{
    public function handle(Contract $contract, UploadedFile $file): Media
    {
        $media = $contract->addMedia($file)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection(Contract::DOCUMENTS);

        activity()->performedOn($contract)->event('document_added')
            ->withProperties(['file' => $media->file_name])->log('แนบไฟล์สัญญา');

        return $media;
    }
}
