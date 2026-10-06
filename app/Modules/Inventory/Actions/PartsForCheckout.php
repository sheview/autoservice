<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * Parts as plain arrays for the issue/loan requests (Asset module): by id, or found by a search
 * (active parts only), with what is on hand and the unit cost (satang) for auto-approval.
 */
class PartsForCheckout
{
    public const LIMIT = 30;

    /**
     * @param  list<int>|null  $ids  these parts (deleted and inactive ones too); null = search
     * @return array<int, array{id: int, code: string, name: string, part_number: string|null, brand: string|null, unit: string,
     *     qty_on_hand: int, unit_cost: int|null, is_active: bool, track_serial: bool}> keyed by id
     */
    public function handle(?array $ids = null, string $search = ''): array
    {
        $query = $ids !== null
            ? Part::withTrashed()->whereKey($ids)
            : Part::query()->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('part_number', 'like', "%{$search}%")))
                ->orderBy('name')
                ->limit(self::LIMIT);

        return $query->get(['id', 'code', 'name', 'part_number', 'brand', 'unit', 'qty_on_hand', 'unit_cost', 'is_active', 'track_serial'])
            ->mapWithKeys(fn (Part $part) => [$part->id => [
                'id' => $part->id,
                'code' => $part->code,
                'name' => $part->name,
                'part_number' => $part->part_number,
                'brand' => $part->brand,
                'unit' => $part->unit,
                'qty_on_hand' => (int) $part->qty_on_hand,
                'unit_cost' => $part->unit_cost,
                'is_active' => (bool) $part->is_active,
                // Issued by choosing pieces (serial numbers), not by a quantity.
                'track_serial' => (bool) $part->track_serial,
            ]])
            ->all();
    }
}
