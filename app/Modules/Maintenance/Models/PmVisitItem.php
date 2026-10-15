<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The result of one asset in a PM round. "checklist" is a copy of the template's items when the
 * round started (template_code / template_version say which); "answers" maps their keys to what the technician recorded. Site photos are
 * the "photos" media collection.
 *
 * @property list<array{key: string, label: string, type: string}> $checklist
 * @property array<string, bool|string|float|null> $answers
 * @property array<string, string>|null $remarks a note per question, by key
 */
class PmVisitItem extends Model implements HasMedia
{
    use BelongsToTenant, InteractsWithMedia;

    public const PHOTOS = 'photos';

    public const MAX_PHOTOS = 10;

    public const RESULT_PENDING = 'pending';

    public const RESULT_PASSED = 'passed';

    public const RESULT_FAILED = 'failed';

    public const RESULT_NOT_INSPECTED = 'not_inspected';

    /** Results a technician can record. */
    public const RESULTS = [self::RESULT_PASSED, self::RESULT_FAILED, self::RESULT_NOT_INSPECTED];

    /** Why a piece of equipment was not inspected (details in note). */
    public const NOT_INSPECTED_REASONS = ['user_absent', 'sent_for_repair', 'not_found', 'other'];

    protected $fillable = [
        'asset_id', 'pm_checklist_id', 'template_code', 'template_version', 'checklist', 'result', 'not_inspected_reason',
        'answers', 'remarks', 'draft', 'instrument_id', 'note', 'ticket_id', 'checked_by', 'checked_at',
    ];

    protected $attributes = [
        'result' => self::RESULT_PENDING,
        'checklist' => '[]',
        'answers' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'answers' => 'array',
            'remarks' => 'array',
            'draft' => 'array',
            'template_version' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTOS)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }
}
