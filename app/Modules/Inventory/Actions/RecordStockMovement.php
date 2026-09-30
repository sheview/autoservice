<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only place stock changes: adds a row to the ledger and moves the part's qty_on_hand with it.
 *
 *   receive, return   $quantity goes into stock
 *   issue             $quantity comes out of stock (never below zero)
 *   adjust            $quantity is the counted stock; the movement is the difference
 *
 * The part row is locked, so two people issuing the last piece cannot both succeed.
 */
class RecordStockMovement
{
    /**
     * @param  array{unit_cost?: int|null, ticket_id?: int|null, reference?: string|null, note?: string|null}  $details  unit_cost in satang
     */
    public function handle(Part $part, string $type, int $quantity, ?User $actor, array $details = []): StockMovement
    {
        $movement = DB::transaction(function () use ($part, $type, $quantity, $actor, $details) {
            // withTrashed: a part deleted after it was issued can still come back from a ticket.
            $locked = Part::withTrashed()->lockForUpdate()->findOrFail($part->id);

            $change = match ($type) {
                StockMovement::TYPE_RECEIVE, StockMovement::TYPE_RETURN => $quantity,
                StockMovement::TYPE_ISSUE => -$quantity,
                StockMovement::TYPE_ADJUST => $quantity - $locked->qty_on_hand,
            };

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

            return $locked->movements()->create([
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
        });

        $part->refresh();

        return $movement;
    }
}
