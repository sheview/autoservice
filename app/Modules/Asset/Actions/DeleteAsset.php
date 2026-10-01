<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes an asset. The asset code stays taken, so a printed label never points to another asset.
 * Its serial numbers are let go, so they can be registered again.
 */
class DeleteAsset
{
    public function handle(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset->serials()->get()->each->delete();
            $asset->delete();
        });
    }
}
