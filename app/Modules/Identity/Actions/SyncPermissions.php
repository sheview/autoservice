<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds any permission in PermissionCatalog that is not in the database yet.
 */
class SyncPermissions
{
    public function handle(): void
    {
        $existing = Permission::query()->pluck('name')->all();
        $missing = array_diff(PermissionCatalog::all(), $existing);

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        if ($missing !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
