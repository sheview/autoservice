<?php

namespace App\Modules\Identity\Policies;

class UserPolicy extends TenantPolicy
{
    protected string $module = 'user';
}
