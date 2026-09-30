<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * pm.* permissions for PM rounds.
 *
 *   update (schedule, assign), cancel   pm.update
 *   perform (start, record, complete)   pm.perform, and be the assignee or hold pm.update
 */
class PmVisitPolicy extends TenantPolicy
{
    protected string $module = 'pm';

    public function perform(User $user, Model $visit): bool
    {
        return $this->permits($user, 'perform') && $this->inScope($user, $visit)
            && ($this->isAssignee($user, $visit) || $this->permits($user, 'update'));
    }

    public function cancel(User $user, Model $visit): bool
    {
        return $this->update($user, $visit);
    }

    private function isAssignee(User $user, Model $visit): bool
    {
        return $visit->getAttribute('assignee_id') !== null
            && (int) $visit->getAttribute('assignee_id') === (int) $user->id;
    }
}
