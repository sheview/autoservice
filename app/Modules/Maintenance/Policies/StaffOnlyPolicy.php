<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * For records that are internal to the MA company (checklists): a customer account never sees
 * them, whatever its role says. They have no branch, customer or owner.
 */
abstract class StaffOnlyPolicy extends TenantPolicy
{
    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;

    public function viewAny(User $user): bool
    {
        return $user->customer_id === null && parent::viewAny($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->customer_id === null && parent::view($user, $model);
    }
}
