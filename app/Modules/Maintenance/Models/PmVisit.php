<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Maintenance\Policies\PmVisitPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A PM work order: one round of a plan, or one made by hand (no plan). Public URLs use "ulid".
 * Its items are the equipment checked (one per asset). It moves through PmWorkflow:
 *
 *   draft → scheduled → in_progress → submitted → pending_approval → approved → published → closed
 *
 * with revision_required (sent back to the technician) and cancelled. completed_at = when the
 * field work was handed in; onsite_at / departed_at = the technician's "arrived" / "left";
 * locked_at = the customer signed, results are final (a correction is a new work order with
 * revision_of_id). The technician's and the customer's signatures are media.
 */
#[UsePolicy(PmVisitPolicy::class)]
class PmVisit extends Model implements HasMedia
{
    use BelongsToTenant, HasUlids, InteractsWithMedia, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_REVISION_REQUIRED = 'revision_required';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_REVISION_REQUIRED,
        self::STATUS_SUBMITTED, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, self::STATUS_PUBLISHED,
        self::STATUS_CLOSED, self::STATUS_CANCELLED,
    ];

    /** Field work still to do: these count as overdue past their due date and can be rescheduled. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_REVISION_REQUIRED];

    /** The technician records results (and photos, signatures) in these. */
    public const WORKING_STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_REVISION_REQUIRED];

    /** The field work was handed in: what reports count as done. */
    public const DONE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, self::STATUS_PUBLISHED, self::STATUS_CLOSED];

    /** An approved report exists. */
    public const REPORTED_STATUSES = [self::STATUS_APPROVED, self::STATUS_PUBLISHED, self::STATUS_CLOSED];

    /** Signed when the work is handed in (PNG drawn on the screen). */
    public const TECHNICIAN_SIGNATURE = 'technician_signature';

    public const CUSTOMER_SIGNATURE = 'customer_signature';

    protected $fillable = [
        'pm_plan_id', 'contract_id', 'customer_id', 'visit_no', 'title', 'round', 'period_starts_on', 'due_on', 'scheduled_on',
        'status', 'assignee_id', 'created_by', 'started_at', 'onsite_at', 'departed_at', 'completed_at', 'submitted_by', 'reviewed_at', 'reviewed_by',
        'approved_at', 'approved_by', 'published_at', 'published_by', 'closed_at', 'closed_by', 'cancelled_at', 'reminded_at',
        'summary', 'office_data', 'revision_note', 'revision_count', 'current_version', 'published_version',
        'locked_at', 'revision_of_id',
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
        'revision_count' => 0,
        'current_version' => 0,
        'published_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'revision_count' => 'integer',
            'current_version' => 'integer',
            'published_version' => 'integer',
            'period_starts_on' => 'date',
            'due_on' => 'date',
            'scheduled_on' => 'date',
            'started_at' => 'datetime',
            'onsite_at' => 'datetime',
            'departed_at' => 'datetime',
            'completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'locked_at' => 'datetime',
            'office_data' => 'array',
        ];
    }

    /**
     * The primary key stays a bigint; only the "ulid" column is generated.
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PmPlan::class, 'pm_plan_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PmVisitItem::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(PmSignature::class);
    }

    /** The locked work order this one corrects. */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PmVisitEvent::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PmReportVersion::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(PmReportShare::class);
    }

    public function registerMediaCollections(): void
    {
        foreach ([self::TECHNICIAN_SIGNATURE, self::CUSTOMER_SIGNATURE] as $collection) {
            $this->addMediaCollection($collection)->singleFile()->acceptsMimeTypes(['image/png']);
        }
    }

    /** The work order's name: its own title, or its plan's. */
    public function displayTitle(): ?string
    {
        return $this->title ?? $this->plan?->title;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** The technician may record results now. */
    public function isWorking(): bool
    {
        return in_array($this->status, self::WORKING_STATUSES, true);
    }

    /** Still to do and past its due date. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on->lt(today());
    }
}
