<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

class AssetCategoryPolicy extends TenantPolicy
{
    protected string $module = 'asset_category';
}
