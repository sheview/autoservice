<?php

namespace App\Modules\Contract\Policies;

use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * customers.* permissions. Customers have no branch; "own" = the customers of the user's own
 * tickets; scope customer = the account's own customer (ContractScope).
 */
class CustomerPolicy extends TenantPolicy
{
    protected string $resource = 'customers';

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = 'id';

    protected function owns(User $user, Model $model): bool
    {
        return ContractScope::ownsCustomer($user, (int) $model->getKey());
    }
}
