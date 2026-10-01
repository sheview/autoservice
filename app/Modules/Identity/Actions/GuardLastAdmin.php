<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Validation\ValidationException;

/**
 * A company always keeps at least one active admin (PermissionCatalog::ADMIN_ROLE): the last one
 * cannot be deactivated, deleted or given another role. (The admin role itself cannot lose
 * permissions either: SaveRoleMatrix refuses to change it.)
 */
class GuardLastAdmin
{
    /**
     * @param  list<string>|null  $roles  the user's roles after the change (null = unchanged)
     * @param  bool  $active  whether the user stays active
     */
    public function handle(User $user, ?array $roles = null, bool $active = true): void
    {
        if (! $user->exists || ! $user->hasRole(PermissionCatalog::ADMIN_ROLE) || ! $user->is_active) {
            return;
        }

        $staysAdmin = $active && ($roles === null || in_array(PermissionCatalog::ADMIN_ROLE, $roles, true));
        if ($staysAdmin) {
            return;
        }

        $others = User::query()
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->role(PermissionCatalog::ADMIN_ROLE)
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages(['role' => __('identity.roles.last_admin')]);
        }
    }
}
