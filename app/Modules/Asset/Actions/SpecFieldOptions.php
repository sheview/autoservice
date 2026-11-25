<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetCategory;

/**
 * The spec fields the company's asset categories have (hostname, CPU, RAID ...), once each by key,
 * with the categories that have them: what a PM template may show from the register
 * (Maintenance: readonly_from_asset "spec.{key}").
 */
class SpecFieldOptions
{
    /**
     * @return list<array{key: string, label: string, categories: list<string>}> by label
     */
    public function handle(): array
    {
        $fields = [];
        foreach (AssetCategory::query()->orderBy('name')->get(['name', 'spec_fields']) as $category) {
            foreach ($category->spec_fields ?? [] as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                $fields[$key] ??= ['key' => $key, 'label' => (string) ($field['label'] ?? $key), 'categories' => []];
                $fields[$key]['categories'][] = $category->name;
            }
        }

        return collect($fields)->sortBy('label')->values()->all();
    }
}
