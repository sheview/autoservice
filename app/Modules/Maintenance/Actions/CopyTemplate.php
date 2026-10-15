<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Maintenance\Models\PmChecklist;
use Illuminate\Support\Str;

/**
 * A new template made from another: a code of its own ({code}-COPY, -COPY2, ...), version 1 as a
 * draft, tied to nothing yet.
 */
class CopyTemplate
{
    public function handle(PmChecklist $source): PmChecklist
    {
        $base = Str::limit($source->code, 33, '').'-COPY';
        $code = $base;
        for ($n = 2; PmChecklist::query()->where('code', $code)->exists(); $n++) {
            $code = $base.$n;
        }

        $copy = $source->replicate(['alive', 'published_at']);
        $copy->fill([
            'code' => $code,
            'name' => __('maintenance.templates.copy_name', ['name' => $source->name]),
            'version' => 1,
            'status' => PmChecklist::STATUS_DRAFT,
            'asset_category_id' => null,
        ])->save();

        return $copy;
    }
}
