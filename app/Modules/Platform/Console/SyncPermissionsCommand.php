<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Actions\SeedDefaultRoles;
use App\Modules\Identity\Actions\SyncPermissions;
use App\Modules\Identity\Actions\SyncRoleGrants;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Run after every deploy: makes the permissions match PermissionCatalog, resets the platform's
 * own roles to the catalog, gives each company's admin role every permission (scope all) and
 * adds a default role a company does not have yet (one added to the catalog later, e.g.
 * purchasing). Other company roles are left alone: each company may have changed them on the roles matrix;
 * with --defaults the default roles (helpdesk, technician, ...) are put back exactly as the
 * catalog (permissions.json) says, and missing default roles are created.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'platform:sync-permissions {--defaults : Reset the default company roles to the catalog}';

    protected $description = 'Bring permissions and roles up to date with the permission catalog';

    public function handle(SyncPermissions $syncPermissions, SeedDefaultRoles $seedDefaultRoles, SyncRoleGrants $syncGrants, TenantContext $context): int
    {
        $syncPermissions->handle();

        $platform = Tenant::query()->where('is_platform', true)->first();
        if ($platform) {
            $seedDefaultRoles->handle($platform);
        }

        $count = 0;
        Tenant::query()->where('is_platform', false)->each(function (Tenant $tenant) use ($context, $seedDefaultRoles, $syncGrants, &$count) {
            if ($this->option('defaults')) {
                $seedDefaultRoles->handle($tenant);
            } else {
                $context->run($tenant, function () use ($syncGrants) {
                    $admin = Role::query()->where('name', PermissionCatalog::ADMIN_ROLE)->first();
                    if ($admin !== null) {
                        $syncGrants->handle($admin, PermissionCatalog::grantsFor(PermissionCatalog::ADMIN_ROLE));
                    }
                    foreach (PermissionCatalog::DEFAULT_ROLES as $name => $role) {
                        if (! Role::query()->where('name', $name)->exists()) {
                            $created = Role::create(['name' => $name, 'guard_name' => 'web', 'label' => $role['label'], 'is_system' => true]);
                            $syncGrants->handle($created, PermissionCatalog::grantsFor($name));
                        }
                    }
                });
            }
            $count++;
        });

        $this->call('permission:cache-reset');
        $this->info("Permissions are up to date (platform + {$count} companies).");

        return self::SUCCESS;
    }
}
