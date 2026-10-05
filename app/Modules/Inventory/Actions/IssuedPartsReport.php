<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Platform\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Parts that left stock (used, lent, put in as a spare) in a period, newest first, as plain rows
 * for the Reporting module: the part, how many, the serial numbers of the pieces (as written when
 * they went out, and which of them came back since), the ticket, the cost. Searched by part code,
 * name or serial; narrowed to some tickets (those of a contract or customer) when given.
 */
class IssuedPartsReport
{
    /**
     * @param  array{q?: string|null, ticket_ids?: list<int>|null}  $filters  ticket_ids null = every movement
     * @return LengthAwarePaginator<int, array<string, mixed>>|list<array<string, mixed>> paginated when $perPage is given
     */
    public function handle(CarbonInterface $from, CarbonInterface $to, array $filters = [], ?int $perPage = null): LengthAwarePaginator|array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $like = '%'.addcslashes($q, '%_\\').'%';

        $query = StockMovement::query()
            ->with('part:id,code,name,unit,brand,part_number,unit_cost')
            ->whereIn('type', StockMovement::OUT_TYPES)
            ->whereBetween('created_at', [$from, $to])
            ->when(($filters['ticket_ids'] ?? null) !== null, fn (Builder $query) => $query->whereIn('ticket_id', $filters['ticket_ids']))
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->whereHas('part', fn (Builder $part) => $part->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like))
                ->orWhereIn('id', PartUnitEvent::query()->select('stock_movement_id')->where('action', PartUnitEvent::ACTION_ISSUE)
                    ->where('serial_number', 'ilike', $like))))
            ->orderByDesc('created_at')->orderByDesc('id');

        $rows = fn ($movements) => $this->rows($movements);

        if ($perPage === null) {
            return $rows($query->limit(5000)->get());
        }

        $page = $query->paginate($perPage)->withQueryString();

        return $page->setCollection(collect($rows($page->getCollection())));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows($movements): array
    {
        $events = PartUnitEvent::query()->whereIn('stock_movement_id', $movements->pluck('id'))
            ->where('action', PartUnitEvent::ACTION_ISSUE)->orderBy('id')->get(['id', 'part_unit_id', 'stock_movement_id', 'serial_number']);
        // Pieces taken back since (to stock), so the report can tell.
        $back = PartUnitEvent::query()->whereIn('part_unit_id', $events->pluck('part_unit_id'))
            ->where('action', PartUnitEvent::ACTION_RETURN)->get(['part_unit_id', 'id']);

        return $movements->map(function (StockMovement $movement) use ($events, $back) {
            $pieces = $events->where('stock_movement_id', $movement->id);

            return [
                'id' => $movement->id,
                'at' => $movement->created_at->toIso8601String(),
                'type' => $movement->type,
                'part' => $movement->part?->only(['id', 'code', 'name', 'unit', 'brand', 'part_number']),
                'quantity' => -$movement->quantity,
                'serials' => $pieces->pluck('serial_number')->values()->all(),
                // Came back after this hand-out (a piece may go out, back, and out again).
                'returned' => $pieces->filter(fn ($piece) => $back->where('part_unit_id', $piece->part_unit_id)->where('id', '>', $piece->id)->isNotEmpty())
                    ->pluck('serial_number')->values()->all(),
                'ticket_id' => $movement->ticket_id,
                'reference' => $movement->reference,
                'note' => $movement->note,
                'user_name' => $movement->user_name,
                'value' => Money::toBaht($movement->part?->unit_cost === null ? null : $movement->part->unit_cost * -$movement->quantity),
            ];
        })->values()->all();
    }
}
