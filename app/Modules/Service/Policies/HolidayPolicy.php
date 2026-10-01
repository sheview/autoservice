<?php

namespace App\Modules\Service\Policies;

use App\Modules\Identity\Policies\TenantPolicy;

/**
 * holidays.view sees the company's days off; holidays.manage adds and removes them. Holidays
 * belong to the whole company: no branch, no customer.
 */
class HolidayPolicy extends TenantPolicy
{
    protected string $resource = 'holidays';

    protected array $actions = ['create' => 'manage', 'update' => 'manage', 'delete' => 'manage'];

    protected ?string $branchColumn = null;

    protected ?string $customerColumn = null;
}
