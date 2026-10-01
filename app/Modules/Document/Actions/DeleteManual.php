<?php

namespace App\Modules\Document\Actions;

use App\Modules\Document\Models\Manual;

/**
 * Removes a manual (soft delete: its files stay with the record, as for other business data).
 */
class DeleteManual
{
    public function handle(Manual $manual): void
    {
        $manual->delete();
    }
}
