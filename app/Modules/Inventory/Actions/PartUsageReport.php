<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Money;
use Carbon\CarbonInterface;

/**
 * Stock figures of the current tenant, as plain arrays, for the Reporting module (which must not
 * use the Inventory models directly): what moved in a period, and how the stock stands today.
 * "Used" is what left stock (issued, lent or put in as a spare) minus what came back; its value
 * uses each part's latest unit cost.
 */
class PartUsageReport
{
    public const TOP_PARTS = 10;

    /**
     * @return array{received: int, used: int, used_on_tickets: int, used_value: string, low: int, out: int,
     *     top: list<array{code: string, name: string, unit: string, quantity: int, value: string|null}>}
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $inPeriod = fn () => StockMovement::query()->whereBetween('created_at', [$from, $to]);
        // Everything that left stock (used, lent, put in as a spare) minus what came back.
        $usage = [...StockMovement::OUT_TYPES, StockMovement::TYPE_RETURN];

        $used = $inPeriod()->whereIn('type', $usage)
            ->groupBy('part_id')
            ->selectRaw('part_id, -sum(quantity) as used')
            ->pluck('used', 'part_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->filter(fn (int $quantity) => $quantity > 0)
            ->sortDesc();

        $parts = Part::withTrashed()->whereKey($used->keys())->get(['id', 'code', 'name', 'unit', 'unit_cost'])->keyBy('id');
        $value = fn (int $partId, int $quantity) => $parts[$partId]?->unit_cost === null ? null : $parts[$partId]->unit_cost * $quantity;

        return [
            'received' => (int) $inPeriod()->where('type', StockMovement::TYPE_RECEIVE)->sum('quantity'),
            'used' => (int) $used->sum(),
            'used_on_tickets' => max(0, -(int) $inPeriod()->whereIn('type', $usage)->whereNotNull('ticket_id')->sum('quantity')),
            'used_value' => Money::toBaht((int) $used->map(fn (int $quantity, int $partId) => $value($partId, $quantity) ?? 0)->sum()),
            // Today's stock, whatever the period.
            'low' => Part::query()->where('is_active', true)->where('min_qty', '>', 0)->where('qty_on_hand', '>', 0)->whereColumn('qty_on_hand', '<=', 'min_qty')->count(),
            'out' => Part::query()->where('is_active', true)->where('qty_on_hand', 0)->count(),
            'top' => $used->take(self::TOP_PARTS)->map(fn (int $quantity, int $partId) => [
                ...$parts[$partId]->only(['code', 'name', 'unit']),
                'quantity' => $quantity,
                'value' => Money::toBaht($value($partId, $quantity)),
            ])->values()->all(),
        ];
    }
}
