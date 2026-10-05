<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Takes back into stock a part handed out on an issue/loan request line (Asset module), with the
 * reason: chosen pieces of a part followed by serial number (only those that went out on that
 * line), else a quantity. The caller has checked the line and how much of it is still out.
 */
class ReturnIssuedPart
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private ReturnPartUnits $returnUnits,
    ) {}

    /**
     * @param  list<int>  $unitIds  the pieces, for a tracked part (exactly $quantity of them)
     * @param  array{checkout_item_id: int, ticket_id?: int|null, reference?: string|null}  $links
     */
    public function handle(int $partId, int $quantity, array $unitIds, User $actor, string $reason, array $links): StockMovement
    {
        // withTrashed: a part deleted after it went out can still come back.
        $part = Part::withTrashed()->find($partId);
        if ($part === null) {
            throw ValidationException::withMessages(['qty' => __('inventory.ticket_parts.not_found')]);
        }

        if ($part->track_serial) {
            $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
            if (count($unitIds) !== $quantity) {
                throw ValidationException::withMessages(['unit_ids' => __('inventory.units.pick_count', ['name' => $part->name, 'count' => $quantity])]);
            }

            return $this->returnUnits->handle($part, $unitIds, $actor, [
                'checkout_item_id' => $links['checkout_item_id'],
                'reference' => $links['reference'] ?? null,
                'note' => $reason,
            ]);
        }

        return $this->recordMovement->handle($part, StockMovement::TYPE_RETURN, $quantity, $actor, [
            'ticket_id' => $links['ticket_id'] ?? null,
            'reference' => $links['reference'] ?? null,
            'note' => $reason,
        ]);
    }
}
