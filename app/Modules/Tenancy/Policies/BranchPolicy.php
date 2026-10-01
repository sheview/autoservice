<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class BranchPolicy extends TenantPolicy
{
    protected string $resource = 'branches';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    // A branch is "in the user's branch" when it is that branch.
    protected ?string $branchColumn = 'id';

    protected ?string $customerColumn = null;
}
