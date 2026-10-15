<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or updates a PM checklist (its questions, ChecklistItems) and ties it to its category.
 * A new checklist gets the code CL{id}. Work orders already started keep their own copy of the
 * questions.
 */
class SaveChecklist
{
    public function __construct(private AssignTemplateToCategory $assign) {}

    /**
     * @param  array{name: string, asset_category_id?: int|null, items?: list<array<string, mixed>>}  $data  validated
     */
    public function handle(?PmChecklist $checklist, array $data): PmChecklist
    {
        return DB::transaction(function () use ($checklist, $data) {
            $checklist ??= new PmChecklist(['code' => (string) Str::ulid()]);
            $checklist->fill([
                'name' => $data['name'],
                'asset_category_id' => $data['asset_category_id'] ?? null,
                'items' => ChecklistItems::clean($data['items'] ?? []),
            ]);
            $checklist->published_at ??= now();
            $checklist->save();

            if (Str::isUlid($checklist->code)) {
                $checklist->update(['code' => 'CL'.$checklist->id]);
            }
            $this->assign->handle($checklist->code, $checklist->asset_category_id);

            return $checklist;
        });
    }
}
