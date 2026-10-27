<?php

namespace App\Modules\Asset\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * Storage locations: master data of assets and parts like the categories, so the same
 * permissions (asset-categories.view / .manage). They belong to the whole company.
 */
class StorageLocationPolicy extends TenantPolicy
{
    protected string $resource = 'asset-categories';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;
}
