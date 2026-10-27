<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a part and, when asked, receives its first stock in the same transaction (neither is
 * kept without the other): a quantity for a counted part, serial numbers (one piece each) for a
 * part tracked by serial. The receipt takes the part's unit cost and the user as who entered it.
 */
class CreatePart
{
    public function __construct(
        private SavePart $savePart,
        private RecordStockMovement $recordMovement,
        private ReceivePartUnits $receiveUnits,
    ) {}

    /**
     * @param  array<string, mixed>  $data  for SavePart
     * @param  list<string>  $serials  cleaned (PartSerials::clean)
     */
    public function handle(array $data, int $quantity, array $serials, User $actor): Part
    {
        return DB::transaction(function () use ($data, $quantity, $serials, $actor) {
            $part = $this->savePart->handle(null, $data);
            $details = ['unit_cost' => $part->unit_cost, 'note' => __('inventory.parts.initial_note')];

            if ($part->track_serial) {
                if ($quantity > 0 && $serials === []) {
                    throw ValidationException::withMessages(['initial_serials' => __('inventory.parts.initial_needs_serials')]);
                }
                if ($serials !== []) {
                    try {
                        $this->receiveUnits->handle($part, $serials, $actor, $details);
                    } catch (ValidationException $e) {
                        // The serial checks speak of "serials": on this form the box is initial_serials.
                        throw ValidationException::withMessages(['initial_serials' => collect($e->errors())->flatten()->all()]);
                    }
                }
            } elseif ($serials !== []) {
                throw ValidationException::withMessages(['initial_qty' => __('inventory.parts.initial_not_tracked')]);
            } elseif ($quantity > 0) {
                $this->recordMovement->handle($part, StockMovement::TYPE_RECEIVE, $quantity, $actor, $details);
            }

            return $part->fresh();
        });
    }
}
