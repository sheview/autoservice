<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;

/**
 * Spare parts of the current company for people of another company (cross-company sharing):
 * what it is and how many are in stock, read-only. Called inside the owner company through
 * ShareGateway, which has already decided the person may look. Costs are not shown.
 */
class SharedPartSearch
{
    /**
     * @return list<array{code: string, name: string, part_number: string|null, brand: string|null, unit: string|null, qty_on_hand: int}>
     */
    public function handle(string $search, int $limit = 30): array
    {
        $search = trim($search);

        return Part::query()
            ->where('is_active', true)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', "%{$search}%")
                ->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('part_number', 'ilike', "%{$search}%")
                ->orWhere('brand', 'ilike', "%{$search}%")))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Part $part) => [
                ...$part->only(['code', 'name', 'part_number', 'brand', 'unit']),
                'qty_on_hand' => (int) $part->qty_on_hand,
            ])
            ->all();
    }
}
