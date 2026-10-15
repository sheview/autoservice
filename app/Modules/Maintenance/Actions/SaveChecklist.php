<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;

/**
 * Creates or updates a PM checklist (its questions, ChecklistItems). Work orders already started
 * keep their own copy of the questions.
 */
class SaveChecklist
{
    /**
     * @param  array{name: string, asset_category_id?: int|null, items?: list<array<string, mixed>>}  $data  validated
     */
    public function handle(?PmChecklist $checklist, array $data): PmChecklist
    {
        $checklist ??= new PmChecklist;
        $checklist->fill([
            'name' => $data['name'],
            'asset_category_id' => $data['asset_category_id'] ?? null,
            'items' => ChecklistItems::clean($data['items'] ?? []),
        ]);
        $checklist->save();

        return $checklist;
    }
}
