<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use Illuminate\Validation\ValidationException;

/**
 * Removes a role the company made itself, once nobody holds it (its grants go with it). The
 * seeded roles stay: the system relies on them (admin, technician, customer accounts...).
 * A role is a setting, not business data, so it is deleted for good; the activity log keeps it.
 */
class DeleteRole
{
    public function handle(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('identity.roles.system_locked')]);
        }

        $holders = $role->users()->count();
        if ($holders > 0) {
            throw ValidationException::withMessages(['role' => __('identity.roles.in_use', ['count' => $holders])]);
        }

        activity()->performedOn($role)->event('role_deleted')
            ->withProperties(['old' => $role->only(['name', 'label'])])
            ->log('ลบบทบาท '.($role->label ?? $role->name));

        $role->delete();
    }
}
