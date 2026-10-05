<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Takes a part out of stock for a ticket (or an issue/loan request line). The caller has already
 * checked that $actor may do it. A part followed by serial number goes by its pieces, chosen.
 */
class IssuePartToTicket
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private IssuePartUnits $issueUnits,
    ) {}

    /**
     * @param  int|null  $ticketId  null = taken for another company's job (a request through a share; the note says which)
     * @param  string  $type  one of StockMovement::OUT_TYPES: used up (issue), lent (loan) or put in as a spare
     * @param  list<int>  $unitIds  for a part followed by serial number: the pieces, exactly $quantity of them
     * @param  array{asset_id?: int|null, checkout_item_id?: int|null, checkout_fulfillment_id?: int|null, reference?: string|null}  $links
     *                                                                                                                                       where the pieces go: the device they are put into, the request line and hand-over they went out on
     */
    public function handle(?int $ticketId, int $partId, int $quantity, User $actor, ?string $note = null, string $type = StockMovement::TYPE_ISSUE,
        array $unitIds = [], array $links = []): StockMovement
    {
        if (! in_array($type, StockMovement::OUT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('validation.in', ['attribute' => __('inventory.fields.type')])]);
        }

        // Parts of another tenant are not found (tenant scope + RLS).
        $part = Part::query()->where('is_active', true)->find($partId);
        if ($part === null) {
            throw ValidationException::withMessages(['part_id' => __('inventory.ticket_parts.not_found')]);
        }

        if ($part->track_serial) {
            $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
            if (count($unitIds) !== $quantity) {
                throw ValidationException::withMessages(['unit_ids' => __('inventory.units.pick_count', ['name' => $part->name, 'count' => $quantity])]);
            }

            return $this->issueUnits->handle($part, $unitIds, $actor, $type, ['ticket_id' => $ticketId, 'note' => $note] + $links);
        }

        return $this->recordMovement->handle($part, $type, $quantity, $actor, [
            'ticket_id' => $ticketId,
            'note' => $note,
        ]);
    }
}
