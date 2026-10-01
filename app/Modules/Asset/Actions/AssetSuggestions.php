<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Values already used on the tenant's assets, offered in the asset form: brands with how many
 * assets use each (the form warns when a new one looks like a more used one, e.g. "Ciso" for
 * "Cisco") and sub-types.
 */
class AssetSuggestions
{
    private const LIMIT = 500;

    /**
     * @return array{brands: list<array{name: string, count: int}>, subtypes: list<string>}
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
        ];
    }
}
