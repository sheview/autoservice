<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * pm-plans.* for PM plans. Plans have no branch (a contract's assets may be in many branches),
 * so scope branch reaches every plan; customer = plans of the account's customer; own = plans
 * the user is the technician of.
 */
class PmPlanPolicy extends TenantPolicy
{
    protected string $resource = 'pm-plans';

    protected ?string $projectColumn = 'contract_id';

    protected ?string $branchColumn = null;

    protected function owns(User $user, Model $model): bool
    {
        return $model->getAttribute('assignee_id') !== null && (int) $model->getAttribute('assignee_id') === (int) $user->id;
    }
}
