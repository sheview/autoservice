<?php

namespace App\Modules\Document\Concerns;

use App\Modules\Document\Support\Attachments;

/**
 * For a model (implementing Spatie\MediaLibrary\HasMedia, using InteractsWithMedia) that carries
 * attached files. Its registerMediaCollections() calls registerAttachmentCollection().
 */
trait HasAttachments
{
    /** The media collection of the files; a model with older files may keep its own name. */
    public function attachmentCollection(): string
    {
        return Attachments::COLLECTION;
    }

    /** Whether scans (JPG, PNG) are accepted besides Word, Excel and PDF. */
    public function attachmentsTakeImages(): bool
    {
        return false;
    }

    /** The largest file accepted, in KB. */
    public function attachmentMaxKb(): int
    {
        return Attachments::MAX_KB;
    }

    protected function registerAttachmentCollection(): void
    {
        $this->addMediaCollection($this->attachmentCollection())->acceptsMimeTypes(Attachments::mimeTypes($this->attachmentsTakeImages()));
    }
}
