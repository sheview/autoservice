<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Takes a part out of stock for a ticket. The caller (Service module) has already checked that
 * $actor may work on the ticket.
 */
class IssuePartToTicket
{
    public function __construct(private RecordStockMovement $recordMovement) {}

    public function handle(int $ticketId, int $partId, int $quantity, User $actor, ?string $note = null): StockMovement
    {
        // Parts of another tenant are not found (tenant scope + RLS).
        $part = Part::query()->where('is_active', true)->find($partId);
        if ($part === null) {
            throw ValidationException::withMessages(['part_id' => __('inventory.ticket_parts.not_found')]);
        }

        return $this->recordMovement->handle($part, StockMovement::TYPE_ISSUE, $quantity, $actor, [
            'ticket_id' => $ticketId,
            'note' => $note,
        ]);
    }
}
