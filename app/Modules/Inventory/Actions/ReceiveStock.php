<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSerials;

/**
 * Goods received straight into stock, not through a purchase request: a quantity of a part, or
 * for a part followed by serial number one serial per unit (exactly as many as received).
 * Returns the movement and the serials found elsewhere in the company (a warning only).
 */
class ReceiveStock
{
    public function __construct(
        private RecordStockMovement $recordMovement,
        private ReceivePartUnits $receiveUnits,
    ) {}

    /**
     * @param  list<string>  $serials  cleaned (PartSerials::clean)
     * @param  array{unit_cost?: int|null, reference?: string|null, note?: string|null, supplier?: string|null,
     *     warranty_until?: string|null, received_on?: string|null}  $details  unit_cost in satang
     * @return array{movement: StockMovement, elsewhere: list<string>}
     */
    public function handle(Part $part, int $quantity, array $serials, User $actor, array $details = []): array
    {
        if (! $part->track_serial) {
            return ['movement' => $this->recordMovement->handle($part, StockMovement::TYPE_RECEIVE, $quantity, $actor, $details), 'elsewhere' => []];
        }

        PartSerials::check($part, $serials, $quantity);
        $elsewhere = PartSerials::elsewhere($part, $serials);

        return ['movement' => $this->receiveUnits->handle($part, $serials, $actor, $details), 'elsewhere' => $elsewhere];
    }
}
