<?php

namespace App\Modules\Document\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Attaches files to a record that uses HasAttachments, and writes it to the record's history.
 * The uploaded files are kept, so the same files can be attached to several records.
 */
class AddAttachments
{
    /**
     * @param  HasMedia&Model  $model
     * @param  list<UploadedFile>  $files
     * @return list<Media>
     */
    public function handle(HasMedia $model, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $added = array_map(fn (UploadedFile $file) => $model->addMedia($file)
            ->preservingOriginal()
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->usingFileName($this->fileName($file))
            ->toMediaCollection($model->attachmentCollection()), $files);

        activity()->performedOn($model)->event('attachment_added')
            ->withProperties(['files' => array_map(fn (Media $media) => $media->file_name, $added)])
            ->log('แนบไฟล์');

        return $added;
    }

    /** The original name, without characters that are unsafe in a path. */
    private function fileName(UploadedFile $file): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $file->getClientOriginalName()) ?? 'file';

        return trim($name, ' .-') ?: 'file';
    }
}
