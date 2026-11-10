<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\Asset;

/**
 * The office has checked an asset registered on site (AddAssetFromSite) and filled in what it
 * needed: it no longer waits to be confirmed. Where it came from (added_note) is kept.
 */
class ConfirmAssetReview
{
    public function handle(Asset $asset): void
    {
        $asset->update(['needs_review' => false]);
    }
}
