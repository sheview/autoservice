<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use App\Modules\Inventory\Models\PartUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The pieces chosen of a part, locked, each in the status the change starts from: a piece not
 * of this part (or of another company), or not there (already issued, say), refuses them all.
 */
class PickPartUnits
{
    /**
     * @param  list<int>  $unitIds
     * @param  array{ticket_id?: int|null, checkout_item_id?: int|null}  $heldBy  for a return: where they must be now (null = anywhere)
     * @return Collection<int, PartUnit>
     */
    public function handle(Part $part, array $unitIds, string $status, array $heldBy = [], string $field = 'unit_ids'): Collection
    {
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        if ($unitIds === []) {
            throw ValidationException::withMessages([$field => __('inventory.units.pick_required', ['name' => $part->name])]);
        }

        $units = PartUnit::query()->where('part_id', $part->id)->whereKey($unitIds)->orderBy('id')->lockForUpdate()->get();
        $wrong = $units->filter(fn (PartUnit $unit) => $unit->status !== $status
            || collect($heldBy)->filter(fn ($id) => $id !== null)->contains(fn ($id, $column) => (int) $unit->{$column} !== (int) $id));

        if ($units->count() !== count($unitIds) || $wrong->isNotEmpty()) {
            throw ValidationException::withMessages([$field => __("inventory.units.not_{$status}", [
                'serials' => $wrong->pluck('serial_number')->implode(', ') ?: '-',
            ])]);
        }

        return $units;
    }
}
