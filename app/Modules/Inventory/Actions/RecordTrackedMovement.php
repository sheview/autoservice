<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\PartSerials;

/**
 * A stock change entered on the page of a part tracked by serial number, by its pieces:
 * receive = the serials of the new pieces; issue / loan / spare = pieces in stock; return =
 * pieces out; adjust = pieces in stock taken off as broken (with the reason).
 * Returns the movement and the serials received that are found elsewhere in the company.
 */
class RecordTrackedMovement
{
    public function __construct(
        private ReceivePartUnits $receive,
        private IssuePartUnits $issue,
        private ReturnPartUnits $return,
        private RemovePartUnits $remove,
    ) {}

    /**
     * @param  array{serials?: list<string>, unit_ids?: list<int>, unit_cost?: int|null, reference?: string|null, note?: string|null,
     *     supplier?: string|null, warranty_until?: string|null}  $data
     * @return array{movement: StockMovement, elsewhere: list<string>}
     */
    public function handle(Part $part, string $type, array $data, User $actor): array
    {
        $details = ['reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null];
        $units = $data['unit_ids'] ?? [];

        if ($type === StockMovement::TYPE_RECEIVE) {
            $serials = $data['serials'] ?? [];
            $elsewhere = PartSerials::elsewhere($part, $serials);
            $movement = $this->receive->handle($part, $serials, $actor, $details + [
                'unit_cost' => $data['unit_cost'] ?? null,
                'supplier' => $data['supplier'] ?? null,
                'warranty_until' => $data['warranty_until'] ?? null,
            ]);

            return ['movement' => $movement, 'elsewhere' => $elsewhere];
        }

        $movement = match ($type) {
            StockMovement::TYPE_RETURN => $this->return->handle($part, $units, $actor, $details),
            StockMovement::TYPE_ADJUST => $this->remove->handle($part, $units, $actor, (string) ($data['note'] ?? ''), $details['reference']),
            default => $this->issue->handle($part, $units, $actor, $type, $details),
        };

        return ['movement' => $movement, 'elsewhere' => []];
    }
}
