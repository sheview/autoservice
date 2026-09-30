<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;

/**
 * Creates or updates a PM checklist. Rounds already started keep their own copy of the items.
 */
class SaveChecklist
{
    /**
     * @param  array{name: string, asset_category_id?: int|null, items?: list<array{key: string, label: string, type: string}>}  $data  validated
     */
    public function handle(?PmChecklist $checklist, array $data): PmChecklist
    {
        $checklist ??= new PmChecklist;
        $checklist->fill([
            'name' => $data['name'],
            'asset_category_id' => $data['asset_category_id'] ?? null,
            'items' => array_values(array_map(
                fn (array $item) => ['key' => $item['key'], 'label' => $item['label'], 'type' => $item['type']],
                $data['items'] ?? [],
            )),
        ]);
        $checklist->save();

        return $checklist;
    }
}
