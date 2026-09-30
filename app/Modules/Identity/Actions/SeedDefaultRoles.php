<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Creates the default roles of a tenant.
 * Customer tenants get DEFAULT_ROLES; the platform tenant gets PLATFORM_ROLES (superadmin and
 * the central staff roles).
 */
class SeedDefaultRoles
{
    public function __construct(
        private SyncPermissions $syncPermissions,
        private TenantContext $context,
    ) {}

    public function handle(Tenant $tenant): void
    {
        $this->syncPermissions->handle();

        $this->context->run($tenant, function () use ($tenant) {
            if ($tenant->is_platform) {
                foreach (PermissionCatalog::PLATFORM_ROLES as $name => $role) {
                    $this->seed($name, $role['label'], PermissionCatalog::platformPermissionsFor($name));
                }

                return;
            }

            foreach (PermissionCatalog::DEFAULT_ROLES as $name => $role) {
                $this->seed($name, $role['label'], PermissionCatalog::permissionsFor($name));
            }
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function seed(string $name, string $label, array $permissions): void
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['label' => $label, 'is_system' => true],
        );

        $role->syncPermissions($permissions);
    }
}
