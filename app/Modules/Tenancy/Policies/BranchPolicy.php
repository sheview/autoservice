<?php

namespace App\Modules\Tenancy\Policies;

use App\Modules\Identity\Policies\TenantPolicy;
use Illuminate\Database\Eloquent\Model;

class BranchPolicy extends TenantPolicy
{
    protected string $module = 'branch';

    protected function branchIdOf(Model $model): ?int
    {
        return $model->getKey();
    }
}
