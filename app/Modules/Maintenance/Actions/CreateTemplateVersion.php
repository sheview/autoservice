<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use Illuminate\Support\Facades\DB;

/**
 * The draft to change a template with: the code's draft when there is one, else a copy of the
 * given version as the next version number. The published version stays as it is until the draft
 * is published.
 */
class CreateTemplateVersion
{
    public function handle(PmChecklist $template): PmChecklist
    {
        return DB::transaction(function () use ($template) {
            $versions = PmChecklist::query()->where('code', $template->code)->lockForUpdate()->get();
            $draft = $versions->firstWhere('status', PmChecklist::STATUS_DRAFT);
            if ($draft) {
                return $draft;
            }

            $next = $template->replicate(['alive', 'published_at']);
            $next->fill(['version' => $versions->max('version') + 1, 'status' => PmChecklist::STATUS_DRAFT])->save();

            return $next;
        });
    }
}
