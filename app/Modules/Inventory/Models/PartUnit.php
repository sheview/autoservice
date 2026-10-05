<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of a part tracked by serial number. Its status is changed only through the actions
 * of the Inventory module (ReceivePartUnits, IssuePartUnits, ReturnPartUnits, RemovePartUnits,
 * StartTrackingSerials), each writing a part_unit_events row; the part's qty_on_hand is always
 * the number of its pieces in stock. Ticket, asset and request line belong to other modules.
 */
class PartUnit extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_ISSUED = 'issued';

    /** Taken off the stock as broken (or not found when the part started to be tracked). */
    public const STATUS_REMOVED = 'removed';

    public const STATUSES = [self::STATUS_IN_STOCK, self::STATUS_ISSUED, self::STATUS_REMOVED];

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_RECEIVE = 'receive';

    public const SOURCE_BACKFILL = 'backfill';

    protected $fillable = [
        'part_id', 'serial_number', 'unit_cost', 'received_on', 'source', 'purchase_receipt_id', 'supplier', 'warranty_until',
        'status', 'ticket_id', 'asset_id', 'checkout_item_id', 'note',
    ];

    protected $attributes = ['status' => self::STATUS_IN_STOCK];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'integer',
            'received_on' => 'date',
            'warranty_until' => 'date',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(PartUnitEvent::class);
    }
}
