<?php

namespace App\Modules\Service\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class HolidayPolicy extends TenantPolicy
{
    protected string $module = 'holiday';
}
