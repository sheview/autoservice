<?php

namespace App\Modules\Service\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Service\Models\FieldTechnician;

/**
 * Staff approve an outside technician who signed up (then they are found when looking for someone
 * to send on a job), or turn them down; an approved one may be turned down later, and the other
 * way round.
 */
class ReviewFieldTechnician
{
    public function handle(FieldTechnician $technician, bool $approve, User $by): FieldTechnician
    {
        $technician->forceFill([
            'status' => $approve ? FieldTechnician::STATUS_APPROVED : FieldTechnician::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by_name' => $by->name,
        ])->save();

        return $technician;
    }
}
