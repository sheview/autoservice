<?php

namespace App\Modules\Service\Actions;

use App\Modules\Service\Models\RepairPreset;

/**
 * The company's symptom and fix chips, in order. A company that has not set its own yet gets a
 * starting list (RepairPreset::DEFAULTS from the lang file), which its admin can then change.
 */
class RepairPresetList
{
    /**
     * @return array{symptom: list<string>, solution: list<string>}
     */
    public function handle(): array
    {
        $saved = RepairPreset::query()->orderBy('sort')->orderBy('id')->get(['kind', 'label'])->groupBy('kind');

        $list = [];
        foreach (RepairPreset::KINDS as $kind) {
            $list[$kind] = isset($saved[$kind])
                ? $saved[$kind]->pluck('label')->values()->all()
                : array_values((array) __("service.presets.defaults.{$kind}"));
        }

        return $list;
    }
}
