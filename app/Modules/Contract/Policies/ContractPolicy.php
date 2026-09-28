<?php

namespace App\Modules\Contract\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class ContractPolicy extends TenantPolicy
{
    protected string $module = 'contract';
}
