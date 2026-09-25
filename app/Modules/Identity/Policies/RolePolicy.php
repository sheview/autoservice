<?php

namespace App\Modules\Identity\Policies;

class RolePolicy extends TenantPolicy
{
    protected string $module = 'role';
}
