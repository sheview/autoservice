<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a part that was issued to a ticket back into stock: at most what the ticket still holds.
 */
class ReturnPartFromTicket
{
    public function __construct(private RecordStockMovement $recordMovement) {}

    public function handle(int $ticketId, int $partId, int $quantity, User $actor, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($ticketId, $partId, $quantity, $actor, $note) {
            // Locked so two returns of the same part cannot both count the same pieces.
            $part = Part::withTrashed()->lockForUpdate()->find($partId);
            $held = $part === null ? 0 : -(int) StockMovement::query()
                ->where('ticket_id', $ticketId)
                ->where('part_id', $part->id)
                ->sum('quantity');

            if ($part === null || $quantity > $held) {
                throw ValidationException::withMessages(['quantity' => __('inventory.ticket_parts.return_too_many', ['held' => max($held, 0)])]);
            }

            return $this->recordMovement->handle($part, StockMovement::TYPE_RETURN, $quantity, $actor, [
                'ticket_id' => $ticketId,
                'note' => $note,
            ]);
        });
    }
}
