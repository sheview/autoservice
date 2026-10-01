<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;

/**
 * Creates the default roles of a tenant with their default grants (permission + scope).
 * Customer tenants get DEFAULT_ROLES; the platform tenant gets PLATFORM_ROLES (superadmin and
 * the central staff roles, whose grants are company-wide).
 */
class SeedDefaultRoles
{
    public function __construct(
        private SyncPermissions $syncPermissions,
        private SyncRoleGrants $syncGrants,
        private TenantContext $context,
    ) {}

    public function handle(Tenant $tenant): void
    {
        $this->syncPermissions->handle();

        $this->context->run($tenant, function () use ($tenant) {
            if ($tenant->is_platform) {
                foreach (PermissionCatalog::PLATFORM_ROLES as $name => $role) {
                    $this->seed($name, $role['label'], array_fill_keys(PermissionCatalog::platformPermissionsFor($name), PermissionCatalog::SCOPE_ALL));
                }

                return;
            }

            foreach (PermissionCatalog::DEFAULT_ROLES as $name => $role) {
                $this->seed($name, $role['label'], PermissionCatalog::grantsFor($name));
            }
        });
    }

    /**
     * @param  array<string, string>  $grants
     */
    private function seed(string $name, string $label, array $grants): void
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['label' => $label, 'is_system' => true],
        );
        $this->syncGrants->handle($role, $grants);
    }
}
