<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;

class AssetPolicy extends TenantPolicy
{
    protected string $module = 'asset';

    public function import(User $user): bool
    {
        return $this->permits($user, 'import');
    }

    public function export(User $user): bool
    {
        return $this->permits($user, 'export');
    }
}
