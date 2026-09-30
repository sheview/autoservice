<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * pm.* for records that are internal to the MA company (plans, checklists): a customer account
 * may see its PM rounds (pm.view) but never these.
 */
abstract class StaffOnlyPolicy extends TenantPolicy
{
    protected string $module = 'pm';

    public function viewAny(User $user): bool
    {
        return $user->customer_id === null && parent::viewAny($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->customer_id === null && parent::view($user, $model);
    }
}
