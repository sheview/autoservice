<?php

namespace App\Modules\Platform\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\StaffPool;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a staff pool in step: every active person of the "from" company with one of its roles has
 * an active linked account in the "to" company (SetStaffCompany); when the pool is switched off,
 * or the person is deactivated in their own company, that account is switched off. Accounts are
 * never deleted, so the work done and assigned in the "to" company stays with them.
 *
 * Run when a pool is saved, when a user of the "from" company is saved (event UserSaved), and
 * every hour (platform:sync-staff-pools) for anything missed.
 */
class SyncStaffPool
{
    public function __construct(
        private SetStaffCompany $setCompany,
        private TenantContext $context,
    ) {}

    /**
     * @param  int|null  $userId  only this person of the "from" company
     * @return int how many people are in the pool now
     */
    public function handle(StaffPool $pool, ?int $userId = null): int
    {
        $pool->loadMissing('fromTenant', 'toTenant');
        if ($pool->fromTenant === null || $pool->toTenant === null) {
            return 0;
        }

        $people = $this->context->run($pool->fromTenant, function () use ($pool, $userId) {
            // Roles the company does not have (any more) are left out instead of failing.
            $roles = Role::whereIn('name', $pool->roles ?? [])->pluck('name')->all();

            return $roles === [] ? collect() : User::role($roles)
                ->whereNull('login_user_id')
                ->whereNull('customer_id')
                ->when($userId, fn ($q, int $id) => $q->whereKey($id))
                ->get();
        });

        $on = 0;
        foreach ($people as $person) {
            $active = $pool->is_active && $person->is_active && $pool->toTenant->isActive();
            try {
                $this->setCompany->handle($person, $pool->toTenant, $active);
            } catch (ValidationException) {
                // The company's last admin stays on; nothing else to do for this person.
                continue;
            }
            $on += $active ? 1 : 0;
        }

        return $on;
    }
}
