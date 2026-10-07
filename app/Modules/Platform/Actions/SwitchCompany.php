<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\CrossTenant\LinkedAccounts;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A person who works in several companies moves to another one: the account to log in as there
 * is their own (linked) row of that company, active, in an active company. Written into that
 * company's log.
 */
class SwitchCompany
{
    public function __construct(
        private LinkedAccounts $linkedAccounts,
        private TenantContext $context,
    ) {}

    public function handle(User $user, Tenant $tenant): User
    {
        $account = $tenant->is_platform || ! $tenant->isActive() ? null : $this->linkedAccounts->accountIn($user, $tenant->id);

        if ($account === null) {
            throw new AuthorizationException;
        }

        $this->context->run($tenant, function () use ($account, $user) {
            activity()->causedBy($account)->performedOn($account)->event('company_switched')
                ->withProperties(['from_tenant_id' => $user->tenant_id])
                ->log('เปลี่ยนมาทำงานที่บริษัทนี้');
        });

        return $account;
    }
}
