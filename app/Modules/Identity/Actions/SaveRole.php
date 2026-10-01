<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;

/**
 * Creates a role of the current tenant (with no permissions: they are given on the roles matrix,
 * SaveRoleMatrix) or renames one. System roles keep their name; only the label changes.
 */
class SaveRole
{
    /**
     * @param  array{name?: string, label: string}  $data
     */
    public function handle(?Role $role, array $data): Role
    {
        if ($role === null) {
            $role = Role::create(['name' => $data['name'], 'label' => $data['label'], 'guard_name' => 'web']);
            activity()->performedOn($role)->event('role_created')
                ->withProperties(['attributes' => $role->only(['name', 'label'])])
                ->log('เพิ่มบทบาท '.$role->label);

            return $role;
        }

        $old = $role->only(['name', 'label']);
        $role->label = $data['label'];
        if (! $role->is_system && isset($data['name'])) {
            $role->name = $data['name'];
        }
        $role->save();

        if ($role->wasChanged(['name', 'label'])) {
            activity()->performedOn($role)->event('role_renamed')
                ->withProperties(['old' => $old, 'attributes' => $role->only(['name', 'label'])])
                ->log('แก้ไขบทบาท '.$role->label);
        }

        return $role;
    }
}
