<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Models\PmTemplateAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a draft. A published or archived version stays: work and reports may point at it (they
 * keep their own copy anyway). The code's assignments go when nothing of the code is left.
 */
class DeleteTemplateDraft
{
    public function handle(PmChecklist $template): void
    {
        if ($template->status !== PmChecklist::STATUS_DRAFT) {
            throw ValidationException::withMessages(['template' => __('maintenance.templates.not_draft')]);
        }

        DB::transaction(function () use ($template) {
            $template->delete();
            if (! PmChecklist::query()->where('code', $template->code)->exists()) {
                PmTemplateAssignment::query()->where('template_code', $template->code)->delete();
            }
        });
    }
}
