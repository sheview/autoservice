<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * Asset categories: seen with asset-categories.view, created, changed and deleted with
 * asset-categories.manage. Categories belong to the whole company (no branch, no customer).
 */
class AssetCategoryPolicy extends TenantPolicy
{
    protected string $resource = 'asset-categories';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;
}
