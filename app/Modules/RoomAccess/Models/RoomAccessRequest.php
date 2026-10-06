<?php

namespace App\Modules\RoomAccess\Models;

use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\RoomAccess\Policies\RoomAccessRequestPolicy;
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
 * A request to enter a customer's server room: when, who, why, what equipment, which ticket or
 * MA contract, the rules version accepted, and its documents. Status changes go through the
 * actions of the module, each writing a room_access_events row. The ticket, contract and
 * customer live in other modules (read through their actions).
 */
#[UsePolicy(RoomAccessRequestPolicy::class)]
class RoomAccessRequest extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, HasUlids, InteractsWithMedia, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_INSIDE = 'inside';

    public const STATUS_EXITED = 'exited';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** Approved, but nobody went in before the planned end. */
    public const STATUS_OVERDUE = 'overdue';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_INSIDE, self::STATUS_EXITED,
        self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_OVERDUE,
    ];

    /** Still to happen or happening. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_INSIDE];

    protected $fillable = [
        'request_no', 'status', 'round', 'approved_at', 'server_room_id', 'customer_id', 'requester_id', 'requester_name', 'planned_start', 'planned_end',
        'purpose', 'ticket_id', 'contract_id', 'rule_version_id', 'decision_note', 'submitted_at', 'entered_at', 'exited_at',
        'work_summary', 'items_confirmed_at', 'id_numbers_purged_at', 'entered_by_name', 'exited_by_name', 'items_confirmed_by_name',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'planned_start' => 'datetime',
            'planned_end' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'round' => 'integer',
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'items_confirmed_at' => 'datetime',
            'id_numbers_purged_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ServerRoom::class, 'server_room_id')->withTrashed();
    }

    public function people(): HasMany
    {
        return $this->hasMany(RoomAccessPerson::class, 'request_id')->orderBy('position');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RoomAccessItem::class, 'request_id')->orderBy('position');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(RoomRuleAcceptance::class, 'request_id')->orderBy('accepted_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RoomAccessApproval::class, 'request_id')->orderBy('decided_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RoomAccessEvent::class, 'request_id')->orderBy('created_at')->orderBy('id');
    }

    public function attachmentsTakeImages(): bool
    {
        return true;
    }

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }
}
