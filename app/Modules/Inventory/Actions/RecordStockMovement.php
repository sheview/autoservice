<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Events\PartRestocked;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use App\Modules\Inventory\Models\PartUnitEvent;
use App\Modules\Inventory\Models\StockMovement;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only place stock changes: adds a row to the ledger and moves the part's qty_on_hand with it.
 *
 *   receive, return   $quantity goes into stock
 *   issue, loan, spare   $quantity comes out of stock (never below zero)
 *   adjust            $quantity is the counted stock; the movement is the difference (for a
 *                     tracked part: the pieces taken off)
 *
 * The part row is locked, so two people issuing the last piece cannot both succeed.
 *
 * A part tracked by serial number moves only with its pieces: $details['units'] changes them
 * (ReceivePartUnits, IssuePartUnits, ReturnPartUnits, RemovePartUnits) while the part is locked
 * and returns the part_unit_events to write; the stock is then recounted from the pieces in
 * stock, never added up, so the number cannot drift from them.
 */
class RecordStockMovement
{
    /**
     * @param  array{unit_cost?: int|null, ticket_id?: int|null, reference?: string|null, note?: string|null,
     *     units?: Closure(Part): list<array<string, mixed>>}  $details  unit_cost in satang
     */
    public function handle(Part $part, string $type, int $quantity, ?User $actor, array $details = []): StockMovement
    {
        $movement = DB::transaction(function () use ($part, $type, $quantity, $actor, $details) {
            // withTrashed: a part deleted after it was issued can still come back from a ticket.
            $locked = Part::withTrashed()->lockForUpdate()->findOrFail($part->id);

            $change = match ($type) {
                StockMovement::TYPE_RECEIVE, StockMovement::TYPE_RETURN => $quantity,
                StockMovement::TYPE_ISSUE, StockMovement::TYPE_LOAN, StockMovement::TYPE_SPARE => -$quantity,
                StockMovement::TYPE_ADJUST => $quantity - $locked->qty_on_hand,
            };

            $events = [];
            if ($locked->track_serial) {
                if (! isset($details['units'])) {
                    throw ValidationException::withMessages(['serials' => __('inventory.units.pick_required', ['name' => $locked->name])]);
                }
                $events = ($details['units'])($locked);
                $counted = $locked->units()->where('status', PartUnit::STATUS_IN_STOCK)->count();
                // An adjustment of a tracked part is whatever pieces were taken off (RemovePartUnits).
                $expected = $type === StockMovement::TYPE_ADJUST ? $counted : $locked->qty_on_hand + $change;
                if ($counted !== $expected) {
                    // The pieces changed do not add up to the movement: a bug, never saved.
                    throw new LogicException("Part {$locked->id}: {$counted} pieces in stock, expected {$expected}");
                }
                $change = $counted - $locked->qty_on_hand;
            }

            if ($change === 0) {
                throw ValidationException::withMessages(['quantity' => __('inventory.movements.unchanged')]);
            }

            $balance = $locked->qty_on_hand + $change;
            if ($balance < 0) {
                throw ValidationException::withMessages(['quantity' => __('inventory.movements.insufficient', [
                    'available' => $locked->qty_on_hand, 'unit' => $locked->unit,
                ])]);
            }

            $unitCost = $type === StockMovement::TYPE_RECEIVE ? ($details['unit_cost'] ?? null) : null;

            $locked->qty_on_hand = $balance;
            if (! $locked->isLow()) {
                // Restocked: the next shortage is e-mailed again (NotifyLowStock).
                $locked->low_stock_notified_at = null;
            }
            if ($unitCost !== null) {
                $locked->unit_cost = $unitCost;
            }
            $locked->save();

            $movement = $locked->movements()->create([
                'type' => $type,
                'quantity' => $change,
                'balance_after' => $balance,
                'unit_cost' => $unitCost,
                'ticket_id' => $details['ticket_id'] ?? null,
                'reference' => $details['reference'] ?? null,
                'note' => $details['note'] ?? null,
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
            ]);

            foreach ($events as $event) {
                PartUnitEvent::create($event + [
                    'stock_movement_id' => $movement->id,
                    'ticket_id' => $details['ticket_id'] ?? null,
                    'reference' => $details['reference'] ?? null,
                    'user_id' => $actor?->id,
                    'user_name' => $actor?->name,
                ]);
            }

            return $movement;
        });

        $part->refresh();

        if ($movement->quantity > 0) {
            PartRestocked::dispatch($part->id, (int) $part->qty_on_hand);
        }

        return $movement;
    }
}
