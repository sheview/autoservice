<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates a role of the current tenant.
 * System roles keep their name; only label and permissions change.
 */
class SaveRole
{
    /**
     * @param  array{name?: string, label: string, permissions: list<string>}  $data
     */
    public function handle(?Role $role, array $data): Role
    {
        $invalid = array_diff($data['permissions'], PermissionCatalog::tenantPermissions());
        if ($invalid !== []) {
            throw new InvalidArgumentException('Permissions not allowed for a tenant role: '.implode(', ', $invalid));
        }

        return DB::transaction(function () use ($role, $data) {
            if ($role === null) {
                $role = Role::create(['name' => $data['name'], 'label' => $data['label'], 'guard_name' => 'web']);
            } else {
                $role->label = $data['label'];
                if (! $role->is_system && isset($data['name'])) {
                    $role->name = $data['name'];
                }
                $role->save();
            }

            $role->syncPermissions($data['permissions']);

            return $role;
        });
    }
}
