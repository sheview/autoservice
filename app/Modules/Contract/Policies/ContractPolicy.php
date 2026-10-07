<?php

namespace App\Modules\Contract\Policies;

use App\Modules\Contract\Support\ContractScope;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

/**
 * contracts.* permissions. Contracts have no branch; "own" = contracts of the customers of the
 * user's own tickets; scope project = those and the projects whose team the user is on; scope customer = contracts of the account's customer (ContractScope).
 */
class ContractPolicy extends TenantPolicy
{
    protected string $resource = 'contracts';

    protected ?string $branchColumn = null;

    protected ?string $projectColumn = 'id';

    protected function owns(User $user, Model $model): bool
    {
        return ContractScope::ownsCustomer($user, $model->getAttribute('customer_id') === null ? null : (int) $model->getAttribute('customer_id'));
    }
}
