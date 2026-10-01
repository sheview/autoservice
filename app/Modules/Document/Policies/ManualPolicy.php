<?php

namespace App\Modules\Document\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * Manuals: manuals.view to read them, manuals.manage to add, change and remove them and their
 * files. They belong to the whole company (no branch, no customer).
 */
class ManualPolicy extends TenantPolicy
{
    protected string $resource = 'manuals';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;
}
