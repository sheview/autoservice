<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class PmPlanPolicy extends TenantPolicy
{
    protected string $module = 'pm';
}
