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

    /**
     * @param  int|null  $ticketId  null = taken for another company's job (a request through a share; the note says which)
     * @param  string  $type  one of StockMovement::OUT_TYPES: used up (issue), lent (loan) or put in as a spare
     */
    public function handle(?int $ticketId, int $partId, int $quantity, User $actor, ?string $note = null, string $type = StockMovement::TYPE_ISSUE): StockMovement
    {
        if (! in_array($type, StockMovement::OUT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('validation.in', ['attribute' => __('inventory.fields.type')])]);
        }

        // Parts of another tenant are not found (tenant scope + RLS).
        $part = Part::query()->where('is_active', true)->find($partId);
        if ($part === null) {
            throw ValidationException::withMessages(['part_id' => __('inventory.ticket_parts.not_found')]);
        }

        return $this->recordMovement->handle($part, $type, $quantity, $actor, [
            'ticket_id' => $ticketId,
            'note' => $note,
        ]);
    }
}
