<?php

namespace App\Modules\Identity\Policies;

/**
 * Roles and their grants: one permission, roles.manage, for seeing and changing them.
 */
class RolePolicy extends TenantPolicy
{
    protected string $resource = 'roles';

    protected array $actions = ['view' => 'manage', 'create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;
}
