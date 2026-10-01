<?php

namespace App\Modules\Document\Actions;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Removes an attached file (the row and the stored file) and writes it to the record's history.
 */
class DeleteAttachment
{
    /**
     * @param  HasMedia&Model  $model
     */
    public function handle(HasMedia $model, Media $media): void
    {
        $name = $media->file_name;
        $media->delete();

        activity()->performedOn($model)->event('attachment_deleted')
            ->withProperties(['file' => $name])->log('ลบไฟล์แนบ');
    }
}
