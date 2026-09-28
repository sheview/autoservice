<?php

namespace App\Modules\Contract\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class CustomerPolicy extends TenantPolicy
{
    protected string $module = 'customer';
}
