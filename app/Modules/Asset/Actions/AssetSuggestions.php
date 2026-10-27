<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Values already used on the tenant's assets, offered in the asset form: brands with how many
 * assets use each (the form warns when a new one looks like a more used one, e.g. "Ciso" for
 * "Cisco") and sub-types; and the most used values of some fields, offered as buttons to tap
 * instead of typing (QuickPicks.vue).
 */
class AssetSuggestions
{
    private const LIMIT = 500;

    // Buttons per field.
    public const PICKS = 12;

    // Units offered before the company has used its own.
    public const UNITS = ['ชิ้น', 'เครื่อง', 'ตัว', 'อัน', 'ชุด', 'เส้น', 'ม้วน', 'กล่อง'];

    /**
     * @return array{brands: list<array{name: string, count: int}>, subtypes: list<string>, picks: array<string, mixed>}
     */
    public function handle(): array
    {
        return [
            'brands' => Asset::query()
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->groupBy('brand')
                ->orderBy('brand')
                ->limit(self::LIMIT)
                ->get(['brand', DB::raw('count(*) as uses')])
                ->map(fn ($row) => ['name' => $row->brand, 'count' => (int) $row->uses])
                ->all(),
            'subtypes' => Asset::query()
                ->whereNotNull('subtype')
                ->where('subtype', '!=', '')
                ->distinct()
                ->orderBy('subtype')
                ->limit(self::LIMIT)
                ->pluck('subtype')
                ->all(),
            'picks' => [
                'units' => array_values(array_unique([...$this->mostUsed('unit'), ...self::UNITS])),
                'departments' => $this->mostUsed('department'),
                // Per category: a sub-type belongs to the kind of device.
                'subtypes' => Asset::query()
                    ->whereNotNull('subtype')
                    ->where('subtype', '!=', '')
                    ->groupBy('category_id', 'subtype')
                    ->orderByDesc(DB::raw('count(*)'))
                    ->limit(self::LIMIT)
                    ->get(['category_id', 'subtype'])
                    ->map(fn ($row) => ['category_id' => (int) $row->category_id, 'name' => $row->subtype])
                    ->all(),
            ],
        ];
    }

    /** @return list<string> the values of a column used most, most used first */
    private function mostUsed(string $column): array
    {
        return Asset::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy($column)
            ->limit(self::PICKS)
            ->pluck($column)
            ->all();
    }
}
