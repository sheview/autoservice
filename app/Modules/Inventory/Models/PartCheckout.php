<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Spare parts issued or lent to someone, like an asset's issue/loan form. pending → approved
 * (the stock goes out) → returned for a loan, or pending → rejected / cancelled. An issue is used
 * up: it ends at approved. Pending forms hold their quantity. Public URLs use "ulid".
 */
class PartCheckout extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    /** Taken to be used (consumed). */
    public const TYPE_ISSUE = 'issue';

    /** Lent until due_on, then back into stock. */
    public const TYPE_LOAN = 'loan';

    public const TYPES = [self::TYPE_ISSUE, self::TYPE_LOAN];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RETURNED, self::STATUS_CANCELLED];

    protected $fillable = [
        'part_id', 'contract_id', 'checkout_no', 'type', 'quantity', 'status',
        'borrower_user_id', 'borrower_name', 'borrower_department', 'borrower_phone', 'purpose', 'due_on',
        'requested_by', 'requested_by_name', 'decided_by', 'decided_by_name', 'decided_at', 'decision_note',
        'returned_by', 'returned_by_name', 'returned_at', 'return_note',
    ];

    protected $attributes = [
        'quantity' => 1,
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'due_on' => 'date',
            'decided_at' => 'datetime',
            'returned_at' => 'datetime',
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

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    /** Still to do something about: waiting for a decision, or a loan not back yet. */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING || ($this->status === self::STATUS_APPROVED && $this->type === self::TYPE_LOAN);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->type === self::TYPE_LOAN && $this->due_on !== null && $this->due_on->lt(today());
    }
}
