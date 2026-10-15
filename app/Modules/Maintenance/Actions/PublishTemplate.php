<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a draft: work started from now on uses it, and the version published before it is
 * archived. Work already started keeps its own copy of the version it began with.
 */
class PublishTemplate
{
    public function handle(PmChecklist $template): PmChecklist
    {
        return DB::transaction(function () use ($template) {
            $template = PmChecklist::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->status !== PmChecklist::STATUS_DRAFT) {
                throw ValidationException::withMessages(['template' => __('maintenance.templates.not_draft')]);
            }
            if ($template->items === [] || ($errors = ChecklistItems::schemaErrors($template->items)) !== []) {
                throw ValidationException::withMessages(['items' => $errors ?? [__('maintenance.templates.no_items')]]);
            }

            PmChecklist::query()
                ->where('code', $template->code)->where('status', PmChecklist::STATUS_PUBLISHED)
                ->update(['status' => PmChecklist::STATUS_ARCHIVED]);
            $template->update(['status' => PmChecklist::STATUS_PUBLISHED, 'published_at' => now()]);

            return $template;
        });
    }
}
