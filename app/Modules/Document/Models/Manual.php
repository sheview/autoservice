<?php

namespace App\Modules\Document\Models;

use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Document\Policies\ManualPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A manual of the company: links to read online and/or attached files (PDF, Office, pictures),
 * grouped by category. Everyone in the company reads them (manuals.view); manuals.manage keeps them.
 */
#[UsePolicy(ManualPolicy::class)]
class Manual extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** Manuals are bigger than everyday papers. */
    public const MAX_KB = 10240;

    /** At most this many links on one manual. */
    public const MAX_LINKS = 10;

    protected $fillable = ['title', 'category', 'description', 'links', 'created_by', 'created_by_name'];

    protected $attributes = ['links' => '[]'];

    protected function casts(): array
    {
        return ['links' => 'array'];
    }

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }

    public function attachmentsTakeImages(): bool
    {
        return true;
    }

    public function attachmentMaxKb(): int
    {
        return self::MAX_KB;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'category', 'description', 'links'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
