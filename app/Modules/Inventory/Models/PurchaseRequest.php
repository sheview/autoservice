<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Document\Concerns\HasAttachments;
use App\Modules\Inventory\Policies\PurchaseRequestPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A request to buy something the company does not have (e.g. nothing spare was found to lend).
 * pending → approved → ordered → partially_received → received → registered → issued, or
 * rejected / cancelled (PurchaseWorkflow). Delivered in one or more receipts; qty_received,
 * qty_registered and qty_issued count how far it got. unit_price is an estimate in satang; links
 * are the product pages; quotations are attached (HasAttachments).
 */
#[UsePolicy(PurchaseRequestPolicy::class)]
class PurchaseRequest extends Model implements HasMedia
{
    use BelongsToTenant, HasAttachments, HasUlids, InteractsWithMedia, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_ORDERED, self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED,
        self::STATUS_REGISTERED, self::STATUS_ISSUED, self::STATUS_REJECTED, self::STATUS_CANCELLED,
    ];

    /** Still on its way: waiting, approved, ordered or partly delivered. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_ORDERED, self::STATUS_PARTIALLY_RECEIVED];

    /** Some of it has arrived: what arrived can be registered and handed out. */
    public const RECEIVING_STATUSES = [self::STATUS_PARTIALLY_RECEIVED, self::STATUS_RECEIVED, self::STATUS_REGISTERED, self::STATUS_ISSUED];

    /** What it becomes once it arrives (item_kind): an asset of asset_category_id, or stock of a part. */
    public const KIND_ASSET = 'asset';

    public const KIND_PART = 'part';

    /** At most this many product links. */
    public const MAX_LINKS = 5;

    protected $fillable = [
        'pr_no', 'contract_id', 'checkout_request_id', 'status', 'item_name', 'description', 'quantity', 'qty_received', 'qty_registered', 'qty_issued', 'unit', 'item_kind', 'asset_category_id', 'unit_price', 'links', 'reason', 'needed_by',
        'requested_by', 'requested_by_name', 'decided_by', 'decided_by_name', 'decided_at', 'decision_note',
        'ordered_by_name', 'ordered_at', 'order_note', 'received_by_name', 'received_at', 'receive_note',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'quantity' => 1,
        'qty_received' => 0,
        'qty_registered' => 0,
        'qty_issued' => 0,
        'links' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'qty_received' => 'integer',
            'qty_registered' => 'integer',
            'qty_issued' => 'integer',
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

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchaseRequestEvent::class)->orderBy('id');
    }

    public function registerMediaCollections(): void
    {
        $this->registerAttachmentCollection();
    }
}
