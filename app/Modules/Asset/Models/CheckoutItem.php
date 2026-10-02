<?php

namespace App\Modules\Asset\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line of a checkout request: an asset (issued or lent; a lot asset by quantity) or a part
 * (issued against the request's ticket). pending → approved → partial → fulfilled, or
 * backordered (not enough to hand out) / rejected (with a reason) / cancelled. The part itself
 * lives in the Inventory module: the line keeps its code, name and unit.
 */
class CheckoutItem extends Model
{
    use BelongsToTenant;

    public const TYPE_ASSET = 'asset';

    public const TYPE_PART = 'part';

    public const ISSUE = 'issue';

    public const LOAN = 'loan';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_BACKORDERED = 'backordered';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_PARTIAL, self::STATUS_FULFILLED,
        self::STATUS_BACKORDERED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Still to hand out (some or all of it). */
    public const TO_FULFILL = [self::STATUS_APPROVED, self::STATUS_PARTIAL, self::STATUS_BACKORDERED];

    /** Done with: nothing more to hand out. */
    public const FINISHED = [self::STATUS_FULFILLED, self::STATUS_REJECTED, self::STATUS_CANCELLED];

    protected $fillable = [
        'request_id', 'item_type', 'asset_id', 'part_id', 'item_code', 'item_name', 'unit', 'checkout_type',
        'qty_requested', 'qty_approved', 'qty_fulfilled', 'qty_returned', 'status', 'due_return_date',
        'returned_at', 'returned_by_name', 'return_condition', 'reject_reason', 'purchase_request_id', 'note',
        'backorder_alerted_at', 'overdue_alerted_at',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING, 'checkout_type' => self::ISSUE, 'qty_fulfilled' => 0, 'qty_returned' => 0];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'integer',
            'qty_approved' => 'integer',
            'qty_fulfilled' => 'integer',
            'qty_returned' => 'integer',
            'due_return_date' => 'date',
            'returned_at' => 'datetime',
            'backorder_alerted_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CheckoutRequest::class, 'request_id')->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(CheckoutFulfillment::class, 'item_id')->orderBy('id');
    }

    /** Approved but not handed out yet. */
    public function remaining(): int
    {
        return max(0, (int) $this->qty_approved - (int) $this->qty_fulfilled);
    }

    /** Handed out and not back yet (a loaned asset). */
    public function outstanding(): int
    {
        return max(0, (int) $this->qty_fulfilled - (int) $this->qty_returned);
    }

    public function returnable(): bool
    {
        return $this->item_type === self::TYPE_ASSET && $this->outstanding() > 0;
    }

    public function isOverdue(): bool
    {
        return $this->checkout_type === self::LOAN && $this->outstanding() > 0
            && $this->due_return_date !== null && $this->due_return_date->lt(today());
    }
}
