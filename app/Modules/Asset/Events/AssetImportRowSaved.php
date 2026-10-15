<?php

namespace App\Modules\Asset\Events;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImport;

/**
 * A row of an asset import was saved (ImportAssets), inside the row's transaction: a listener of
 * the module that asked for the import (its context) takes the asset further — e.g. into a PM plan.
 * A listener that throws a ValidationException undoes the row, which is then reported as failed
 * with its messages.
 */
class AssetImportRowSaved
{
    /**
     * @param  array<string, mixed>  $extra  the cells of the context's own columns, by key
     */
    public function __construct(
        public AssetImport $import,
        public Asset $asset,
        public array $extra,
    ) {}
}
