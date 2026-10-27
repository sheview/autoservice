<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Part;
use Illuminate\Support\Facades\DB;

/**
 * The most used units and brands of the tenant's parts, offered on the part form as buttons to
 * tap instead of typing (QuickPicks.vue); common units first when the company has none yet.
 */
class PartSuggestions
{
    public const PICKS = 12;

    public const UNITS = ['ชิ้น', 'ตัว', 'อัน', 'กล่อง', 'เส้น', 'ม้วน', 'ชุด', 'ตลับ', 'แพ็ค'];

    /**
     * @return array{units: list<string>, brands: list<string>}
     */
    public function handle(): array
    {
        return [
            'units' => array_values(array_unique([...$this->mostUsed('unit'), ...self::UNITS])),
            'brands' => $this->mostUsed('brand'),
        ];
    }

    /** @return list<string> */
    private function mostUsed(string $column): array
    {
        return Part::query()
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
