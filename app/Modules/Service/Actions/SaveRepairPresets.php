<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\RepairPreset;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the company's symptom and fix chips with the lists given, in that order.
 */
class SaveRepairPresets
{
    /**
     * @param  array{symptom: list<string>, solution: list<string>}  $lists
     */
    public function handle(array $lists): void
    {
        DB::transaction(function () use ($lists) {
            foreach ($lists as $kind => $labels) {
                RepairPreset::query()->where('kind', $kind)->delete();
                foreach (array_values($labels) as $sort => $label) {
                    RepairPreset::create(['kind' => $kind, 'label' => trim($label), 'sort' => $sort]);
                }
            }
        });
    }
}
