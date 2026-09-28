<?php

namespace App\Modules\Service\Models;

use App\Modules\Service\Policies\TicketPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service job (ticket). Public URLs use "ulid". The SLA values are copied from the contract
 * when the ticket is opened; contract_id null = out of contract (no SLA).
 * Customer, asset, contract and users belong to other modules and are read through their actions.
 */
#[UsePolicy(TicketPolicy::class)]
class Ticket extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const STATUS_NEW = 'new';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD,
        self::STATUS_RESOLVED, self::STATUS_CLOSED, self::STATUS_CANCELLED,
    ];

    /** Statuses in which the job is still running (not done or cancelled). */
    public const OPEN_STATUSES = [self::STATUS_NEW, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public const SOURCES = ['phone', 'email', 'walk_in', 'portal'];

    protected $fillable = [
        'ticket_no', 'customer_id', 'asset_id', 'contract_id', 'branch_id', 'title', 'description',
        'priority', 'status', 'source', 'contact_name', 'contact_phone', 'reported_by', 'assignee_id',
        'service_window', 'response_minutes', 'resolve_minutes', 'response_due_at', 'resolve_due_at',
        'responded_at', 'response_breach_notified_at', 'resolve_breach_notified_at', 'on_hold_since', 'hold_minutes', 'resolved_at', 'closed_at', 'cancelled_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'priority' => 'medium',
        'source' => 'phone',
        'hold_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'response_minutes' => 'integer',
            'resolve_minutes' => 'integer',
            'hold_minutes' => 'integer',
            'response_due_at' => 'datetime',
            'resolve_due_at' => 'datetime',
            'responded_at' => 'datetime',
            'response_breach_notified_at' => 'datetime',
            'resolve_breach_notified_at' => 'datetime',
            'on_hold_since' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
