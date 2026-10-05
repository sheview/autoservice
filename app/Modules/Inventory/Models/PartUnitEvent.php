<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a piece (part_units): what happened, who did it, on which document, and the
 * serial number as it was then. Documents print these rows, so they are never changed.
 */
class PartUnitEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ACTION_RECEIVE = 'receive';

    public const ACTION_BACKFILL = 'backfill';

    public const ACTION_ISSUE = 'issue';

    public const ACTION_RETURN = 'return';

    public const ACTION_REMOVE = 'remove';

    public const ACTION_CORRECT = 'correct';

    protected $fillable = [
        'part_unit_id', 'action', 'from_status', 'to_status', 'serial_number', 'stock_movement_id', 'ticket_id', 'asset_id',
        'checkout_item_id', 'checkout_fulfillment_id', 'reference', 'reason', 'user_id', 'user_name',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PartUnit::class, 'part_unit_id')->withTrashed();
    }
}
