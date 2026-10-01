<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * pm-visits.* for PM rounds. Rounds have no branch; customer = rounds of the account's customer;
 * own = rounds the user is the technician of.
 *
 *   update (schedule, assign), cancel   pm-visits.update
 *   perform (start, record, complete)   pm-visits.complete
 */
class PmVisitPolicy extends TenantPolicy
{
    protected string $resource = 'pm-visits';

    protected array $actions = ['perform' => 'complete', 'cancel' => 'update'];

    protected ?string $branchColumn = null;

    public function perform(User $user, Model $visit): bool
    {
        return $this->permits($user, 'perform') && $this->inScope($user, $visit, 'perform');
    }

    public function cancel(User $user, Model $visit): bool
    {
        return $this->update($user, $visit);
    }

    protected function owns(User $user, Model $model): bool
    {
        return $model->getAttribute('assignee_id') !== null && (int) $model->getAttribute('assignee_id') === (int) $user->id;
    }
}
