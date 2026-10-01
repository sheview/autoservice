<?php

namespace App\Modules\Document\Support;

use Closure;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Files attached to a record (Word, Excel, PDF; a contract also takes scans as JPG/PNG), at most
 * MAX_KB each. Used by the models with the HasAttachments trait; served by ServesAttachments.
 */
class Attachments
{
    public const COLLECTION = 'attachments';

    public const MAX_KB = 2048;

    /** At most this many files in one upload. */
    public const MAX_FILES = 10;

    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /**
     * What the content may really be. Office files are also read as a plain zip (docx, xlsx) or an
     * OLE container (doc, xls) on some systems, so those count too; the extension rule says which.
     */
    public const MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-office',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip-compressed',
        'application/CDFV2',
        'application/x-ole-storage',
    ];

    public const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];

    /** @return list<string> */
    public static function mimeTypes(bool $images = false): array
    {
        return $images ? [...self::MIME_TYPES, ...self::IMAGE_MIME_TYPES] : self::MIME_TYPES;
    }

    /**
     * Validation rules for a list of files under $key (e.g. "attachments").
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $key = 'attachments', bool $images = false, bool $required = false, int $maxKb = self::MAX_KB): array
    {
        $extensions = $images ? [...self::EXTENSIONS, ...self::IMAGE_EXTENSIONS] : self::EXTENSIONS;

        return [
            $key => [$required ? 'required' : 'nullable', 'array', 'max:'.self::MAX_FILES],
            "{$key}.*" => [
                'file',
                'extensions:'.implode(',', $extensions),
                'mimetypes:'.implode(',', self::mimeTypes($images)),
                'max:'.$maxKb,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(string $key = 'attachments'): array
    {
        return [$key => __('document.attachments.field'), "{$key}.*" => __('document.attachments.field')];
    }

    public static function find(HasMedia $model, string $collection, int $id): ?Media
    {
        return $model->getMedia($collection)->firstWhere('id', $id);
    }

    /**
     * The files for the page, oldest first.
     *
     * @param  Closure(int): string  $url  the address of a file (view and delete share it)
     * @return list<array{id: int, name: string, size: int, uploaded_at: string|null, url: string}>
     */
    public static function list(HasMedia $model, string $collection, Closure $url): array
    {
        return $model->getMedia($collection)->map(fn (Media $media) => [
            'id' => $media->id,
            'name' => $media->file_name,
            'size' => (int) $media->size,
            'uploaded_at' => $media->created_at?->toIso8601String(),
            'url' => $url($media->id),
        ])->values()->all();
    }
}
