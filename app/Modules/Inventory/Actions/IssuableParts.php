<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;

/**
 * Active parts that have stock, as plain arrays, for other modules (the ticket page) that let
 * staff pick a part to use and must not use the Part model directly.
 */
class IssuableParts
{
    public const LIMIT = 500;

    /** How a part can leave stock for a ticket: used up, lent, or put in as a spare/replacement. */
    public const TYPES = StockMovement::OUT_TYPES;

    /**
     * @return list<array{id: int, code: string, name: string, unit: string, qty_on_hand: int}>
     */
    public function handle(): array
    {
        return Part::query()
            ->where('is_active', true)
            ->where('qty_on_hand', '>', 0)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'unit', 'qty_on_hand'])
            ->map(fn (Part $part) => $part->only(['id', 'code', 'name', 'unit', 'qty_on_hand']))
            ->all();
    }
}
