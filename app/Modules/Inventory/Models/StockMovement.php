<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the stock ledger. "quantity" is signed (+ into stock, - out of stock) and
 * "balance_after" is the part's stock right after it. Rows are never changed or deleted.
 * The ticket belongs to the Service module and is read through its actions.
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    public const TYPE_RECEIVE = 'receive';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_RETURN = 'return';

    public const TYPE_ADJUST = 'adjust';

    public const TYPES = [self::TYPE_RECEIVE, self::TYPE_ISSUE, self::TYPE_RETURN, self::TYPE_ADJUST];

    /** Types entered on the part page; each needs the permission "stock.{type}". */
    public const MANUAL_TYPES = [self::TYPE_RECEIVE, self::TYPE_ISSUE, self::TYPE_ADJUST];

    protected $fillable = ['type', 'quantity', 'balance_after', 'unit_cost', 'ticket_id', 'reference', 'note', 'user_id', 'user_name'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'balance_after' => 'integer',
            'unit_cost' => 'integer',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class)->withTrashed();
    }
}
