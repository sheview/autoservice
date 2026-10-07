<?php

namespace App\Modules\Contract\Policies;

use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * customers.* permissions. Customers have no branch; "own" = the customers of the user's own
 * tickets; scope project = those and the customers of the projects whose team the user is on;
 * scope customer = the account's own customer (ContractScope).
 */
class CustomerPolicy extends TenantPolicy
{
    protected string $resource = 'customers';

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = 'id';

    /** Scope project: the customers of the projects whose team the user is on. */
    protected function project(): Closure
    {
        return fn (Model $customer, array $contractIds) => in_array((int) $customer->getKey(), ContractScope::projectCustomerIds($contractIds), true);
    }

    protected function owns(User $user, Model $model): bool
    {
        return ContractScope::ownsCustomer($user, (int) $model->getKey());
    }
}
