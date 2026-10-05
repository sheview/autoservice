<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a part that was issued to a ticket back into stock: at most what the ticket still holds;
 * for a part followed by serial number, the pieces chosen of those on the ticket.
 */
class ReturnPartFromTicket
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private ReturnPartUnits $returnUnits,
    ) {}

    /**
     * @param  list<int>  $unitIds  the pieces, for a part followed by serial number
     */
    public function handle(int $ticketId, int $partId, int $quantity, User $actor, ?string $note = null, array $unitIds = []): StockMovement
    {
        return DB::transaction(function () use ($ticketId, $partId, $quantity, $actor, $note, $unitIds) {
            // Locked so two returns of the same part cannot both count the same pieces.
            $part = Part::withTrashed()->lockForUpdate()->find($partId);
            $held = $part === null ? 0 : -(int) StockMovement::query()
                ->where('ticket_id', $ticketId)
                ->where('part_id', $part->id)
                ->sum('quantity');

            if ($part === null || $quantity > $held) {
                throw ValidationException::withMessages(['quantity' => __('inventory.ticket_parts.return_too_many', ['held' => max($held, 0)])]);
            }

            if ($part->track_serial) {
                $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
                if (count($unitIds) !== $quantity) {
                    throw ValidationException::withMessages(['unit_ids' => __('inventory.units.pick_count', ['name' => $part->name, 'count' => $quantity])]);
                }

                return $this->returnUnits->handle($part, $unitIds, $actor, ['ticket_id' => $ticketId, 'note' => $note]);
            }

            return $this->recordMovement->handle($part, StockMovement::TYPE_RETURN, $quantity, $actor, [
                'ticket_id' => $ticketId,
                'note' => $note,
            ]);
        });
    }
}
