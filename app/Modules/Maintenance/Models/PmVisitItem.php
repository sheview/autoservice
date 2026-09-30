<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The result of one asset in a PM round. "checklist" is a copy of the checklist items when the
 * round started; "answers" maps their keys to what the technician recorded.
 *
 * @property list<array{key: string, label: string, type: string}> $checklist
 * @property array<string, bool|string|float|null> $answers
 */
class PmVisitItem extends Model
{
    use BelongsToTenant;

    public const RESULT_PENDING = 'pending';

    public const RESULT_OK = 'ok';

    public const RESULT_ISSUE = 'issue';

    public const RESULT_SKIPPED = 'skipped';

    /** Results a technician can record. */
    public const RESULTS = [self::RESULT_OK, self::RESULT_ISSUE, self::RESULT_SKIPPED];

    protected $fillable = ['asset_id', 'pm_checklist_id', 'checklist', 'result', 'answers', 'note', 'ticket_id', 'checked_by', 'checked_at'];

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
            'checked_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PmVisit::class, 'pm_visit_id');
    }
}
