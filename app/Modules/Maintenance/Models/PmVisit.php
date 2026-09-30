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

/**
 * One PM round of a plan. Public URLs use "ulid". Its items (one per asset) are created when
 * the round starts, from the assets the contract covers at that moment.
 */
#[UsePolicy(PmVisitPolicy::class)]
class PmVisit extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Statuses in which the round still has work to do. */
    public const OPEN_STATUSES = [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS];

    protected $fillable = [
        'pm_plan_id', 'contract_id', 'customer_id', 'visit_no', 'round', 'period_starts_on', 'due_on', 'scheduled_on',
        'status', 'assignee_id', 'started_at', 'completed_at', 'cancelled_at', 'summary',
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'period_starts_on' => 'date',
            'due_on' => 'date',
            'scheduled_on' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Still to do and past its due date. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on->lt(today());
    }
}
