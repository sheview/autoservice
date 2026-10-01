<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An asset issued or lent to someone. pending → approved → returned, or pending → rejected /
 * cancelled. While pending or approved the asset cannot be asked for again. Public URLs use "ulid".
 */
class AssetCheckout extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    /** Handed over to use; comes back when no longer needed. */
    public const TYPE_ISSUE = 'issue';

    /** Lent until due_on. */
    public const TYPE_LOAN = 'loan';

    public const TYPES = [self::TYPE_ISSUE, self::TYPE_LOAN];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_RETURNED, self::STATUS_CANCELLED];

    /** Still holding the asset: asked for, or handed over and not back yet. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    protected $fillable = [
        'asset_id', 'checkout_no', 'type', 'status',
        'borrower_user_id', 'borrower_name', 'borrower_department', 'borrower_phone', 'purpose', 'due_on',
        'requested_by', 'requested_by_name', 'decided_by', 'decided_by_name', 'decided_at', 'decision_note',
        'returned_by', 'returned_by_name', 'returned_at', 'return_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
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

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->due_on !== null && $this->due_on->lt(today());
    }
}
