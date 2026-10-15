<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a template (version 1, a draft) or changes a draft. A published version is never
 * changed: CreateTemplateVersion makes the next draft of it. The assignments belong to the code,
 * so they apply to every version.
 */
class SaveTemplateDraft
{
    public function __construct(private SyncTemplateAssignments $syncAssignments) {}

    /**
     * @param  array{code?: string, name: string, service_line?: string|null, layout: string, blocks?: list<string>,
     *     items: list<array<string, mixed>>, assignments?: list<array<string, int|null>>}  $data  validated
     */
    public function handle(?PmChecklist $template, array $data): PmChecklist
    {
        if ($template && $template->status !== PmChecklist::STATUS_DRAFT) {
            throw ValidationException::withMessages(['template' => __('maintenance.templates.not_draft')]);
        }

        return DB::transaction(function () use ($template, $data) {
            $template ??= new PmChecklist([
                'code' => $data['code'],
                'version' => 1,
                'status' => PmChecklist::STATUS_DRAFT,
            ]);
            $template->fill([
                'name' => $data['name'],
                'service_line' => $data['service_line'] ?? null,
                'layout' => $data['layout'],
                'blocks' => array_merge(array_fill_keys(PmChecklist::BLOCKS, false), array_fill_keys($data['blocks'] ?? [], true)),
                'items' => ChecklistItems::clean($data['items']),
            ])->save();

            if (array_key_exists('assignments', $data)) {
                $this->syncAssignments->handle($template->code, $data['assignments']);
            }

            return $template;
        });
    }
}
