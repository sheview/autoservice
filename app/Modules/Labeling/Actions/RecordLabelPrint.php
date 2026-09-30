<?php

namespace App\Modules\Labeling\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Labeling\Models\AssetLabelPrint;
use Illuminate\Support\Facades\DB;

/**
 * Logs that the user printed labels of these assets.
 */
class RecordLabelPrint
{
    /**
     * @param  list<int>  $assetIds  assets the user may see (checked by the caller)
     */
    public function handle(User $user, array $assetIds, string $template): int
    {
        $now = now();

        DB::transaction(function () use ($user, $assetIds, $template, $now) {
            foreach (array_unique($assetIds) as $assetId) {
                AssetLabelPrint::create(['asset_id' => $assetId, 'user_id' => $user->id, 'template' => $template, 'printed_at' => $now]);
            }
        });

        return count(array_unique($assetIds));
    }
}
