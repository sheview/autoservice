<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\PartUnit;

/**
 * Pieces whose serial number contains the text (ignoring case), the exact one first, for the
 * searches of the part list and the issue/loan requests: each with its part and status.
 */
class FindPartUnits
{
    public const LIMIT = 10;

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $search): array
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return [];
        }

        return PartUnit::query()->with('part:id,code,name')
            ->where('serial_number', 'ilike', '%'.addcslashes($search, '%_\\').'%')
            ->orderByRaw('lower(serial_number) = ? desc', [mb_strtolower($search)])->orderByDesc('id')
            ->limit(self::LIMIT)->get()
            ->map(fn (PartUnit $unit) => [...PartUnitHistory::row($unit), 'part' => $unit->part?->only(['id', 'code', 'name'])])
            ->all();
    }
}
