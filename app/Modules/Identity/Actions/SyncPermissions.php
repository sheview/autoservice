<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Makes the permission rows match PermissionCatalog: adds the missing ones and removes those no
 * longer in it (with their grants), so nothing outside the catalog can be held.
 */
class SyncPermissions
{
    public function handle(): void
    {
        $catalog = PermissionCatalog::all();
        $existing = Permission::query()->pluck('name')->all();

        $missing = array_diff($catalog, $existing);
        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        $stale = array_diff($existing, $catalog);
        if ($stale !== []) {
            Permission::query()->whereIn('name', $stale)->get()->each->delete();
        }

        if ($missing !== [] || $stale !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
