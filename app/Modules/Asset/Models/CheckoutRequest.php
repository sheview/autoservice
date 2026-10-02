<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Policies\CheckoutRequestPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An issue/loan request with lines (CheckoutItem): draft → pending → approved → partial →
 * fulfilled → closed, or rejected / cancelled. After approval the status follows the lines
 * (CheckoutStatus::refresh). Public URLs use "ulid".
 */
#[UsePolicy(CheckoutRequestPolicy::class)]
class CheckoutRequest extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PARTIAL,
        self::STATUS_FULFILLED, self::STATUS_CLOSED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Still in progress: something to decide or hand out. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PARTIAL];

    protected $fillable = [
        'request_no', 'status', 'requester_id', 'requester_name',
        'borrower_user_id', 'borrower_name', 'borrower_department', 'borrower_phone',
        'branch_id', 'ticket_id', 'contract_id', 'purpose', 'needed_by',
        'approved_by', 'approved_by_name', 'approved_at', 'auto_approved', 'reject_reason',
        'submitted_at', 'closed_at', 'approval_alerted_at',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'approval_alerted_at' => 'datetime',
            'auto_approved' => 'boolean',
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

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutItem::class, 'request_id')->orderBy('id');
    }
}
