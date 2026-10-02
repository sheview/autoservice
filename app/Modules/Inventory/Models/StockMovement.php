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

    /** Lent out: expected to come back (a "return"). */
    public const TYPE_LOAN = 'loan';

    /** Put in as a spare or replacement while the customer's own unit is away or broken. */
    public const TYPE_SPARE = 'spare';

    public const TYPE_RETURN = 'return';

    public const TYPE_ADJUST = 'adjust';

    public const TYPES = [self::TYPE_RECEIVE, self::TYPE_ISSUE, self::TYPE_LOAN, self::TYPE_SPARE, self::TYPE_RETURN, self::TYPE_ADJUST];

    /** Types that take stock out, on the part page or for a ticket. */
    public const OUT_TYPES = [self::TYPE_ISSUE, self::TYPE_LOAN, self::TYPE_SPARE];

    /** Types entered on the part page. */
    public const MANUAL_TYPES = self::TYPES;

    /**
     * The permission entering a movement of this type on the part page needs: writing the
     * ledger directly is stock-movements.create for every type. Taking parts out for a ticket is
     * parts.issue instead (Service module); on an issue/loan request, asset-checkouts.fulfill.
     */
    public static function permissionFor(string $type): string
    {
        return 'stock-movements.create';
    }

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
