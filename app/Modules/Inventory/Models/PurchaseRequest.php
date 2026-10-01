<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Inventory\Policies\PurchaseRequestPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A request to buy something the company does not have (e.g. nothing spare was found to lend).
 * pending → approved → ordered → received, or rejected / cancelled. unit_price is an estimate in
 * satang; links are the product pages; quotations are attached (HasAttachments).
 */
#[UsePolicy(PurchaseRequestPolicy::class)]
class PurchaseRequest extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, HasUlids, InteractsWithMedia, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_ORDERED, self::STATUS_RECEIVED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Still on its way: waiting, approved or ordered. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_ORDERED];

    /** At most this many product links. */
    public const MAX_LINKS = 5;

    protected $fillable = [
        'pr_no', 'status', 'item_name', 'description', 'quantity', 'unit', 'unit_price', 'links', 'reason', 'needed_by',
        'requested_by', 'requested_by_name', 'decided_by', 'decided_by_name', 'decided_at', 'decision_note',
        'ordered_by_name', 'ordered_at', 'order_note', 'received_by_name', 'received_at', 'receive_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'quantity' => 1,
        'links' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'links' => 'array',
            'needed_by' => 'date',
            'decided_at' => 'datetime',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
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

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }
}
