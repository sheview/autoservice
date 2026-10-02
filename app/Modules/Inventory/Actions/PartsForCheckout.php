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
     * @return array<int, array{id: int, code: string, name: string, part_number: string|null, unit: string,
     *     qty_on_hand: int, unit_cost: int|null, is_active: bool}> keyed by id
     */
    public function handle(?array $ids = null, string $search = ''): array
    {
        $query = $ids !== null
            ? Part::withTrashed()->whereKey($ids)
            : Part::query()->where('is_active', true)
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('part_number', 'ilike', "%{$search}%")))
                ->orderBy('name')
                ->limit(self::LIMIT);

        return $query->get(['id', 'code', 'name', 'part_number', 'unit', 'qty_on_hand', 'unit_cost', 'is_active'])
            ->mapWithKeys(fn (Part $part) => [$part->id => [
                'id' => $part->id,
                'code' => $part->code,
                'name' => $part->name,
                'part_number' => $part->part_number,
                'unit' => $part->unit,
                'qty_on_hand' => (int) $part->qty_on_hand,
                'unit_cost' => $part->unit_cost,
                'is_active' => (bool) $part->is_active,
            ]])
            ->all();
    }
}
