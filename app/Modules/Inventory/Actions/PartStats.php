<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The figures on top of the part list: active parts and the value of their stock, those running
 * low and those out (as SearchParts' stock filters), and what went out of stock this month.
 */
class PartStats
{
    /**
     * @return array{active: int, stock_value: string|null, low: int, out: int, out_this_month: int} stock_value in baht
     */
    public function handle(): array
    {
        $active = fn () => Part::query()->where('is_active', true);

        return [
            'active' => $active()->count(),
            'stock_value' => Money::toBaht((int) $active()->sum(DB::raw('qty_on_hand * coalesce(unit_cost, 0)'))),
            'low' => $active()->where('min_qty', '>', 0)->where('qty_on_hand', '>', 0)->whereColumn('qty_on_hand', '<=', 'min_qty')->count(),
            'out' => $active()->where('qty_on_hand', 0)->count(),
            // Issued, lent or put in as spares; returns not taken off (they are their own movement).
            'out_this_month' => -(int) StockMovement::query()->whereIn('type', StockMovement::OUT_TYPES)
                ->where('created_at', '>=', now()->startOfMonth())->sum('quantity'),
        ];
    }
}
