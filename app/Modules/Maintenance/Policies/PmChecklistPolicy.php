<?php

namespace App\Modules\Maintenance\Policies;

/**
 * pm-checklists.view to see the checklists, pm-checklists.manage to add, change and delete them.
 */
class PmChecklistPolicy extends StaffOnlyPolicy
{
    protected string $resource = 'pm-checklists';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];
}
